<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Message from 'primevue/message'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import Tag from 'primevue/tag'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const { t } = useI18n()
const { showToast, translateApiError, data, dockerStatusBusy } = useManager()
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
let statusPollTimer = null

const STATUS_POLL_IDLE_MS = 5000
const STATUS_POLL_BUSY_MS = 2000

const stateLabel = computed(() => t(`nginx.state_${nginx.value.state || 'not_created'}`))
const dirty = computed(() => draft.value !== original.value)

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

function enabled(action) {
  if (data.php_controller_daemon?.state !== 'running') return false
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

function stopStatusPoll() {
  if (statusPollTimer) {
    clearInterval(statusPollTimer)
    statusPollTimer = null
  }
}

function startStatusPoll() {
  stopStatusPoll()
  const ms = dockerStatusBusy.value || nginx.value.state === 'busy' ? STATUS_POLL_BUSY_MS : STATUS_POLL_IDLE_MS
  statusPollTimer = setInterval(() => {
    if (document.visibilityState !== 'visible' || pending.value) return
    if (tab.value === 'domain-logs') {
      loadDomainLogList({ silent: true })
      if (selectedDomain.value) openDomainLogs(selectedDomain.value, { silent: true })
      return
    }
    load({ silent: true })
    if (tab.value === 'templates') loadTemplates({ silent: true })
  }, ms)
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
  if (!(await confirmDialog(t('nginx.domain_logs_clear_confirm', { domain: selectedDomain.value }), { acceptLabel: t('action.delete'), rejectLabel: t('action.cancel'), acceptSeverity: 'danger' }))) return
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
  if (!(await confirmDialog(t('nginx.global_log_clear_confirm', { name: t(`nginx.log_${name}`) }), { acceptLabel: t('action.delete'), rejectLabel: t('action.cancel'), acceptSeverity: 'danger' }))) return
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
  if (dirty.value && !(await confirmDialog(t('nginx.template_discard_confirm'), { acceptLabel: t('action.ok'), rejectLabel: t('action.cancel'), acceptSeverity: 'primary' }))) return
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
    // For reload action: keep pending until result arrives via poll
    if (action === 'reload') {
      const previousUpdatedAt = nginx.value.reload_status?.updated_at || ''
      showToast('success', t('reload.waiting'))
      const POLL_INTERVAL = 1500
      const POLL_TIMEOUT = 30000
      const started = Date.now()
      const poll = setInterval(async () => {
        try {
          await load({ silent: true })
          const rs = nginx.value.reload_status
          if (rs && rs.updated_at && rs.updated_at !== previousUpdatedAt) {
            clearInterval(poll)
            const msg = statusText(rs)
            const ok = rs.status === 'success'
            showToast(ok ? 'success' : 'failure', msg)
            pending.value = ''
            if (ok) loadTemplates({ silent: true }).catch(() => {})
          } else if (Date.now() - started > POLL_TIMEOUT) {
            clearInterval(poll)
            showToast('failure', t('reload.timeout'))
            pending.value = ''
          }
        } catch (_) {
          // ignore transient poll errors
        }
      }, POLL_INTERVAL)
      return
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    if (pending.value !== 'reload') pending.value = ''
  }
}

function statusText(status) {
  if (!status) return t('nginx.no_result')
  return status.message_key ? t(status.message_key) : status.message || t('nginx.no_result')
}

function resultSeverity(status) {
  if (!status) return 'secondary'
  if (status.status === 'success') return 'success'
  if (status.status === 'error' || status.status === 'failure') return 'error'
  if (status.status === 'pending' || status.status === 'busy') return 'info'
  return 'warn'
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
  startStatusPoll()
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
  startStatusPoll()
})

onUnmounted(() => {
  stopStatusPoll()
})
</script>

<template>
  <section class="panel nginx-page" data-tour="nginx-panel">
    <div class="panel-heading nginx-heading">
      <div>
        <h2>{{ t('nginx.title') }}</h2>
        <p>{{ t('nginx.subtitle') }}</p>
      </div>
      <div class="panel-heading-actions nginx-heading-actions">
        <Button
          type="button"
          :label="t('nginx.refresh')"
          :disabled="loading || templatesLoading || domainLogsLoading || !!pending"
          @click="refreshCurrentTab"
        />
      </div>
    </div>

    <div class="panel-body nginx-tabs-wrap" data-tour="nginx-tabs">
      <Tabs v-model:value="tab">
        <TabList>
          <Tab value="control" data-tour="nginx-control-tab">{{ t('nginx.tab_control') }}</Tab>
          <Tab value="templates" data-tour="nginx-templates-tab">{{ t('nginx.tab_templates') }}</Tab>
          <Tab value="domain-logs" data-tour="nginx-domain-logs-tab">{{ t('nginx.tab_domain_logs') }}</Tab>
        </TabList>
        <TabPanels>
          <TabPanel value="control">
            <div v-if="loading" class="nginx-tab-pad">{{ t('loading') }}</div>
            <template v-else>
              <div class="nginx-control-stack">
                <div class="nginx-status-bar">
                  <div class="nginx-status-meta">
                    <Tag :value="stateLabel" :severity="stateSeverity(nginx.state)" rounded />
                    <code class="nginx-container">{{ nginx.container }}</code>
                  </div>
                  <div class="controller-actions" data-tour="nginx-actions">
                    <Button
                      type="button"
                      size="small"
                      :label="pending === 'start' ? t('action.working') : t('nginx.start')"
                      :loading="pending === 'start'"
                      :disabled="!enabled('start')"
                      @click="run('start', '/api/nginx/actions/start')"
                    />
                    <Button
                      type="button"
                      size="small"
                      severity="secondary"
                      outlined
                      :label="pending === 'stop' ? t('action.working') : t('nginx.stop')"
                      :loading="pending === 'stop'"
                      :disabled="!enabled('stop')"
                      @click="run('stop', '/api/nginx/actions/stop')"
                    />
                    <Button
                      type="button"
                      size="small"
                      severity="secondary"
                      outlined
                      :label="pending === 'restart' ? t('action.working') : t('nginx.restart')"
                      :loading="pending === 'restart'"
                      :disabled="!enabled('restart')"
                      @click="run('restart', '/api/nginx/actions/restart')"
                    />
                    <Button
                      type="button"
                      size="small"
                      severity="secondary"
                      outlined
                      :label="pending === 'test' ? t('action.working') : t('nginx.test')"
                      :loading="pending === 'test'"
                      :disabled="!enabled('test')"
                      @click="run('test', '/api/nginx/test')"
                    />
                    <Button
                      type="button"
                      size="small"
                      data-tour="nginx-apply-reload"
                      :label="pending === 'reload' ? t('reload.waiting') : t('nginx.apply_reload')"
                      :loading="pending === 'reload'"
                      :disabled="!enabled('reload')"
                      @click="run('reload', '/api/nginx/reload')"
                    />
                  </div>
                </div>

                <Message severity="info" :closable="false" class="nginx-apply-hint">
                  {{ t('nginx.apply_reload_hint') }}
                </Message>

                <div class="nginx-results">
                  <Message
                    :severity="resultSeverity(nginx.test_status)"
                    :closable="false"
                  >
                    <strong>{{ t('nginx.test_result') }}:</strong>
                    {{ statusText(nginx.test_status) }}
                  </Message>
                  <Message
                    v-if="pending === 'reload'"
                    severity="info"
                    :closable="false"
                  >
                    {{ t('reload.waiting') }}
                  </Message>
                  <Message
                    v-else
                    :severity="resultSeverity(nginx.reload_status)"
                    :closable="false"
                  >
                    <strong>{{ t('nginx.reload_result') }}:</strong>
                    {{ statusText(nginx.reload_status) }}
                  </Message>
                </div>

                <div class="nginx-log-grid" data-tour="nginx-logs">
                  <article
                    v-for="name in ['operation', 'error', 'access']"
                    :key="name"
                    class="nginx-log-card"
                  >
                    <div class="nginx-log-card-head">
                      <h3>{{ t(`nginx.log_${name}`) }}</h3>
                      <Button
                        type="button"
                        size="small"
                        severity="danger"
                        outlined
                        :label="
                          pending === `clear-${name}`
                            ? t('action.working')
                            : t('nginx.global_log_clear')
                        "
                        :loading="pending === `clear-${name}`"
                        :disabled="!!pending || loading"
                        @click="clearGlobalLog(name)"
                      />
                    </div>
                    <pre class="nginx-log-pre">{{
                      nginx.logs?.[name]?.available
                        ? nginx.logs[name].content
                        : t('nginx.log_empty')
                    }}</pre>
                  </article>
                </div>
              </div>
            </template>
          </TabPanel>

          <TabPanel value="templates">
            <div class="nginx-templates" data-tour="nginx-templates">
              <div class="nginx-tab-pad nginx-templates-hints">
                <Message severity="info" :closable="false">{{ t('nginx.templates_hint') }}</Message>
                <Message severity="warn" :closable="false">{{ t('nginx.templates_warn') }}</Message>
              </div>
              <div v-if="templatesLoading && templates.length === 0" class="nginx-tab-pad">
                {{ t('loading') }}
              </div>
              <div v-else-if="templates.length === 0" class="nginx-tab-pad empty">
                {{ t('nginx.templates_empty') }}
              </div>
              <div v-else class="nginx-templates-layout">
                <DataTable
                  :value="templates"
                  data-key="name"
                  selection-mode="single"
                  striped-rows
                  :row-class="(row) => (row.name === selectedName ? 'is-selected' : '')"
                  class="nginx-templates-list"
                  @row-click="(e) => openTemplate(e.data.name)"
                >
                  <Column :header="t('nginx.template_name')">
                    <template #body="{ data: item }"><code>{{ item.name }}</code></template>
                  </Column>
                  <Column :header="t('nginx.template_size')">
                    <template #body="{ data: item }">{{ formatSize(item.size) }}</template>
                  </Column>
                  <Column :header="t('nginx.template_updated')">
                    <template #body="{ data: item }">{{ formatTime(item.updated_at) }}</template>
                  </Column>
                </DataTable>
                <div class="nginx-template-editor">
                  <div v-if="!selectedName" class="nginx-pane-empty">{{ t('nginx.template_pick') }}</div>
                  <template v-else>
                    <div class="nginx-template-editor-head">
                      <div class="nginx-template-title">
                        <strong><code>{{ selectedName }}</code></strong>
                        <Tag
                          v-if="dirty"
                          class="home-inline-tag"
                          :value="t('nginx.template_dirty')"
                          severity="warn"
                          rounded
                        />
                        <span v-if="templateMeta" class="nginx-template-meta">
                          {{ formatSize(templateMeta.size) }} · {{ formatTime(templateMeta.updated_at) }}
                        </span>
                      </div>
                      <Button
                        type="button"
                        :label="
                          pending === 'template-save'
                            ? t('action.working')
                            : t('nginx.template_save')
                        "
                        :loading="pending === 'template-save'"
                        :disabled="!dirty || !!pending || templateLoading"
                        @click="saveTemplate"
                      />
                    </div>
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
          </TabPanel>

          <TabPanel value="domain-logs">
            <div class="nginx-domain-logs" data-tour="nginx-domain-logs">
              <div class="nginx-tab-pad">
                <p class="status-line">{{ t('nginx.domain_logs_hint') }}</p>
              </div>
              <div v-if="domainLogsLoading && domainLogList.length === 0" class="nginx-tab-pad">
                {{ t('loading') }}
              </div>
              <div v-else-if="domainLogList.length === 0" class="nginx-tab-pad empty">
                {{ t('nginx.domain_logs_empty') }}
              </div>
              <div v-else class="nginx-templates-layout">
                <DataTable
                  :value="domainLogList"
                  data-key="domain"
                  striped-rows
                  :row-class="(row) => (row.domain === selectedDomain ? 'is-selected' : '')"
                  class="nginx-templates-list"
                  @row-click="(e) => openDomainLogs(e.data.domain)"
                >
                  <Column :header="t('nginx.domain_logs_domain')">
                    <template #body="{ data: item }"><code>{{ item.domain }}</code></template>
                  </Column>
                  <Column :header="t('nginx.log_access')">
                    <template #body="{ data: item }">
                      {{ item.access?.available ? formatSize(item.access.size) : '—' }}
                    </template>
                  </Column>
                  <Column :header="t('nginx.log_error')">
                    <template #body="{ data: item }">
                      {{ item.error?.available ? formatSize(item.error.size) : '—' }}
                    </template>
                  </Column>
                </DataTable>
                <div class="nginx-domain-log-viewer">
                  <div v-if="!selectedDomain" class="nginx-pane-empty">
                    {{ t('nginx.domain_logs_pick') }}
                  </div>
                  <div v-else-if="domainLogsDetailLoading && !domainLogs" class="nginx-pane-empty">
                    {{ t('loading') }}
                  </div>
                  <template v-else-if="domainLogs">
                    <div class="nginx-template-editor-head">
                      <strong><code>{{ domainLogs.domain }}</code></strong>
                      <div class="actions">
                        <Button
                          type="button"
                          size="small"
                          :label="t('nginx.refresh')"
                          :disabled="domainLogsDetailLoading || !!pending"
                          @click="openDomainLogs(selectedDomain)"
                        />
                        <Button
                          type="button"
                          size="small"
                          severity="danger"
                          outlined
                          :label="
                            pending === 'domain-clear'
                              ? t('action.working')
                              : t('nginx.domain_logs_clear')
                          "
                          :loading="pending === 'domain-clear'"
                          :disabled="domainLogsDetailLoading || !!pending"
                          @click="clearDomainLogs"
                        />
                      </div>
                    </div>
                    <div class="nginx-domain-log-grid">
                      <article class="nginx-log-card">
                        <div class="nginx-log-card-head">
                          <h3>
                            {{ t('nginx.log_error') }}
                            <span
                              v-if="domainLogs.error?.updated_at"
                              class="nginx-template-meta"
                            >
                              · {{ formatTime(domainLogs.error.updated_at) }} ·
                              {{ formatSize(domainLogs.error.size) }}
                            </span>
                          </h3>
                        </div>
                        <pre class="nginx-log-pre">{{
                          domainLogs.error?.available
                            ? domainLogs.error.content || t('nginx.log_empty')
                            : t('nginx.log_empty')
                        }}</pre>
                      </article>
                      <article class="nginx-log-card">
                        <div class="nginx-log-card-head">
                          <h3>
                            {{ t('nginx.log_access') }}
                            <span
                              v-if="domainLogs.access?.updated_at"
                              class="nginx-template-meta"
                            >
                              · {{ formatTime(domainLogs.access.updated_at) }} ·
                              {{ formatSize(domainLogs.access.size) }}
                            </span>
                          </h3>
                        </div>
                        <pre class="nginx-log-pre">{{
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
          </TabPanel>
        </TabPanels>
      </Tabs>
    </div>
  </section>
</template>
