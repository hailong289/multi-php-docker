<script setup>
import { computed, defineAsyncComponent, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const {
  data,
  loadBootstrap,
  stateLabel,
  showToast,
  translateApiError,
} = useManager()

const phpService = computed(() => String(route.params.service || ''))
const supervisorService = computed(() => {
  const fromTarget = data.php_controllers?.targets?.[phpService.value]?.supervisor_service
  if (fromTarget) return fromTarget
  if (phpService.value === 'php-8.5') return 'supervisor-8.5'
  if (phpService.value.startsWith('php-')) return `supervisor-${phpService.value.slice(4)}`
  return ''
})

const tab = ref('control')
const loading = ref(true)
const detailsLoading = ref(false)
const pending = ref('')
const selectedLog = ref('')
const followLogs = ref(false)
const clearing = ref(false)
const details = ref(null)
const logPre = ref(null)
let followTimer = null

const configs = ref([])
const confDir = ref('')
const defaultContent = ref('')
const configsLoading = ref(false)
const selectedConf = ref('')
const confDraft = ref('')
const confOriginal = ref('')
const confMeta = ref(null)
const confLoading = ref(false)
const creating = ref(false)
const newConfName = ref('')

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
const confDirty = computed(() => confDraft.value !== confOriginal.value)

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}


function showCreate() {
  return currentState.value === 'not_created'
}

function enabled(action) {
  if (data.php_controller_daemon?.state !== 'running') return false
  if (pending.value) return false
  const state = currentState.value
  if (action === 'create') return state === 'not_created' || state === 'error'
  if (state === 'busy' || state === 'error' || state === 'not_created') return false
  if (action === 'start') return state === 'stopped'
  if (action === 'stop' || action === 'restart') return state === 'running'
  return false
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

async function loadConfigs({ silent = false } = {}) {
  if (!supervisorService.value) return
  if (!silent) configsLoading.value = true
  try {
    const result = await apiGet(`/api/supervisor/${supervisorService.value}/configs`)
    configs.value = result.configs || []
    confDir.value = result.conf_dir || ''
    defaultContent.value = result.default_content || ''
    if (selectedConf.value && !configs.value.some((item) => item.name === selectedConf.value)) {
      selectedConf.value = ''
      confDraft.value = ''
      confOriginal.value = ''
      confMeta.value = null
      creating.value = false
    }
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    if (!silent) configsLoading.value = false
  }
}

async function openConf(name) {
  if (confDirty.value && !(await confirmDialog(t('supervisor.conf_discard_confirm'), { acceptLabel: t('action.ok'), rejectLabel: t('action.cancel'), acceptSeverity: 'primary' }))) return
  creating.value = false
  selectedConf.value = name
  confLoading.value = true
  try {
    const result = await apiGet(
      `/api/supervisor/${supervisorService.value}/configs/${encodeURIComponent(name)}`,
    )
    confDraft.value = result.config?.content || ''
    confOriginal.value = confDraft.value
    confMeta.value = result.config
  } catch (error) {
    showToast('failure', translateApiError(error))
    selectedConf.value = ''
  } finally {
    confLoading.value = false
  }
}

async function startCreateConf() {
  if (confDirty.value && !(await confirmDialog(t('supervisor.conf_discard_confirm'), { acceptLabel: t('action.ok'), rejectLabel: t('action.cancel'), acceptSeverity: 'primary' }))) return
  creating.value = true
  selectedConf.value = ''
  newConfName.value = 'worker.conf'
  confDraft.value = defaultContent.value
  confOriginal.value = confDraft.value
  confMeta.value = null
}

async function saveConf() {
  if (!supervisorService.value) return
  const name = creating.value ? newConfName.value.trim() : selectedConf.value
  if (!name) {
    showToast('failure', t('supervisor.conf_invalid_name'))
    return
  }
  if (!creating.value && !confDirty.value) return
  pending.value = 'conf-save'
  try {
    let result
    if (creating.value) {
      result = await apiSend('POST', `/api/supervisor/${supervisorService.value}/configs`, {
        name,
        content: confDraft.value,
      })
    } else {
      result = await apiSend(
        'PUT',
        `/api/supervisor/${supervisorService.value}/configs/${encodeURIComponent(name)}`,
        { content: confDraft.value },
      )
    }
    if (result.supervisor_services) data.supervisor_services = result.supervisor_services
    showToast('success', t(result.message_key || 'supervisor.conf_saved'))
    creating.value = false
    selectedConf.value = result.config?.name || name
    confOriginal.value = confDraft.value
    confMeta.value = { ...(confMeta.value || {}), ...result.config }
    await loadConfigs({ silent: true })
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function deleteConf(name) {
  const targetName = name || selectedConf.value
  if (!targetName || !supervisorService.value) return
  if (!(await confirmDialog(t('supervisor.conf_delete_confirm', { name: targetName }), { acceptLabel: t('action.delete'), rejectLabel: t('action.cancel'), acceptSeverity: 'danger' }))) return
  pending.value = 'conf-delete'
  try {
    const result = await apiSend(
      'DELETE',
      `/api/supervisor/${supervisorService.value}/configs/${encodeURIComponent(targetName)}`,
    )
    if (result.supervisor_services) data.supervisor_services = result.supervisor_services
    showToast('success', t(result.message_key || 'supervisor.conf_deleted'))
    if (selectedConf.value === targetName) {
      selectedConf.value = ''
      confDraft.value = ''
      confOriginal.value = ''
      confMeta.value = null
      creating.value = false
    }
    await loadConfigs({ silent: true })
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
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
    if (result.supervisor) details.value = result.supervisor
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function clearSelectedLog() {
  if (!supervisorService.value || !selectedLog.value || clearing.value) return
  if (!(await confirmDialog(t('supervisor.clear_confirm', { log: selectedLog.value }), { acceptLabel: t('action.delete'), rejectLabel: t('action.cancel'), acceptSeverity: 'danger' }))) return
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

function onFollowChange(value) {
  followLogs.value = !!value
}

function stopFollow() {
  if (followTimer) {
    clearInterval(followTimer)
    followTimer = null
  }
}

function startStatusPoll() {
  stopFollow()
  if (!supervisorService.value || tab.value !== 'control') return
  const busy = currentState.value === 'busy'
  const ms = followLogs.value ? 3000 : busy ? 2000 : 5000
  followTimer = setInterval(() => {
    if (document.visibilityState !== 'visible' || pending.value) return
    loadDetails({ quiet: true })
  }, ms)
}

function startFollow() {
  startStatusPoll()
}

function refreshCurrent() {
  if (tab.value === 'configs') return loadConfigs()
  return loadBootstrap().then(() => loadDetails())
}

watch(tab, (next) => {
  if (next === 'configs') {
    stopFollow()
    loadConfigs()
  } else {
    startFollow()
  }
})

watch(phpService, async () => {
  selectedLog.value = ''
  details.value = null
  selectedConf.value = ''
  confDraft.value = ''
  confOriginal.value = ''
  creating.value = false
  tab.value = 'control'
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

watch(followLogs, () => {
  startFollow()
  if (followLogs.value) scrollLogToBottom()
})

watch(currentState, () => {
  if (tab.value === 'control') startStatusPoll()
})

watch(
  () => data.supervisor_services?.statuses?.[supervisorService.value],
  (status) => {
    if (!status || !details.value) return
    details.value = { ...details.value, ...status }
  },
)

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
        <Button
          type="button"
          class="icon-back"
          icon="pi pi-arrow-left"
          severity="secondary"
          text
          rounded
          :aria-label="t('supervisor.back_to_php')"
          :title="t('supervisor.back_to_php')"
          @click="router.push({ name: 'php-versions' })"
        />
        <div>
          <h2>{{ t('supervisor.title_for', { version: phpLabel }) }}</h2>
          <p>{{ t('supervisor.subtitle_single') }}</p>
        </div>
      </div>
      <Button
        type="button"
        :label="t('supervisor.refresh')"
        :disabled="loading || !!pending || detailsLoading || configsLoading"
        @click="refreshCurrent"
      />
    </div>

    <div class="panel-body php-detail-tabs-wrap" data-tour="supervisor-tabs">
      <Tabs v-model:value="tab">
        <TabList>
          <Tab value="control" data-tour="supervisor-control-tab">{{ t('supervisor.tab_control') }}</Tab>
          <Tab value="configs" data-tour="supervisor-configs-tab">{{ t('supervisor.tab_configs') }}</Tab>
        </TabList>
        <TabPanels>
          <TabPanel value="control">
            <div v-if="loading" class="panel-body">{{ t('loading') }}</div>
            <template v-else>
              <div class="panel-body nginx-overview">
                <div>
                  <Tag :value="stateLabel(currentState)" :severity="stateSeverity(currentState)" rounded />
                  <code class="php-detail-container">{{ target.container || details?.container || '—' }}</code>
                  <div class="create-hint">
                    <code>{{ supervisorService }}</code>
                    · profile <code>{{ target.profile || supervisorService }}</code>
                  </div>
                  <div v-if="showCreate()" class="create-hint">{{ t('supervisor.create_hint') }}</div>
                </div>
                <div class="controller-actions" data-tour="supervisor-actions">
                  <Button
                    v-if="showCreate()"
                    type="button"
                    size="small"
                    :label="pending === 'create' ? t('action.working') : t('supervisor.create')"
                    :loading="pending === 'create'"
                    :disabled="!enabled('create')"
                    @click="runAction('create')"
                  />
                  <Button type="button" size="small" :label="pending === 'start' ? t('action.working') : t('supervisor.start')" :loading="pending === 'start'" :disabled="!enabled('start')" @click="runAction('start')" />
                  <Button type="button" size="small" severity="secondary" outlined :label="pending === 'stop' ? t('action.working') : t('supervisor.stop')" :loading="pending === 'stop'" :disabled="!enabled('stop')" @click="runAction('stop')" />
                  <Button type="button" size="small" severity="secondary" outlined :label="pending === 'restart' ? t('action.working') : t('supervisor.restart')" :loading="pending === 'restart'" :disabled="!enabled('restart')" @click="runAction('restart')" />
                </div>
              </div>

              <div class="panel-body supervisor-logs" data-tour="supervisor-logs">
                <div class="nginx-heading">
                  <div>
                    <h3>{{ t('supervisor.logs_title') }}</h3>
                    <p v-if="details?.log_dir"><code>{{ details.log_dir }}</code></p>
                  </div>
                  <div class="controller-actions">
                    <label class="follow-toggle" for="supervisor-follow">
                      <ToggleSwitch
                        :model-value="followLogs"
                        input-id="supervisor-follow"
                        @update:model-value="onFollowChange"
                      />
                      <span>{{ t('supervisor.follow') }}</span>
                    </label>
                    <Button type="button" size="small" :label="t('supervisor.refresh_logs')" :disabled="detailsLoading" @click="loadDetails()" />
                    <Button type="button" size="small" severity="secondary" outlined :label="clearing ? t('action.working') : t('supervisor.clear_log')" :loading="clearing" :disabled="!selectedLog || clearing || detailsLoading" @click="clearSelectedLog" />
                  </div>
                </div>

                <div v-if="detailsLoading && !details" class="panel-body">{{ t('loading') }}</div>
                <template v-else-if="details">
                  <div class="supervisor-log-toolbar">
                    <label class="supervisor-log-file">
                      {{ t('supervisor.log_file') }}
                      <Select
                        :model-value="selectedLog"
                        :options="(details.log_files || []).map((name) => ({ label: name, value: name }))"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('supervisor.log_empty')"
                        class="supervisor-log-select"
                        @update:model-value="(v) => { selectedLog = v; loadDetails() }"
                      />
                    </label>
                    <span v-if="details.log?.updated_at" class="create-hint">
                      {{ t('supervisor.log_updated', { at: details.log.updated_at }) }}
                    </span>
                  </div>
                  <article class="nginx-log-card">
                    <pre ref="logPre">{{ details.log?.available ? details.log.content : t('supervisor.log_empty') }}</pre>
                  </article>
                </template>
              </div>
            </template>
          </TabPanel>

          <TabPanel value="configs">
            <div class="supervisor-configs" data-tour="supervisor-configs">
              <div class="panel-body nginx-domain-logs-toolbar">
                <p class="status-line">
                  {{ t('supervisor.conf_hint') }}
                  <code v-if="confDir">{{ confDir }}</code>
                </p>
                <Button type="button" data-tour="supervisor-conf-add" :label="t('supervisor.conf_add')" :disabled="!!pending || configsLoading" @click="startCreateConf" />
              </div>

              <div v-if="configsLoading && configs.length === 0 && !creating" class="panel-body">{{ t('loading') }}</div>
              <div v-else-if="configs.length === 0 && !creating" class="panel-body empty">{{ t('supervisor.conf_empty') }}</div>
              <div v-else class="nginx-templates-layout">
                <DataTable
                  :value="configs"
                  data-key="name"
                  :row-class="(row) => (!creating && row.name === selectedConf ? 'is-selected' : '')"
                  class="nginx-templates-list"
                  @row-click="(e) => openConf(e.data.name)"
                >
                  <Column :header="t('supervisor.conf_name')">
                    <template #body="{ data: item }"><code>{{ item.name }}</code></template>
                  </Column>
                  <Column :header="t('supervisor.conf_size')">
                    <template #body="{ data: item }">{{ formatSize(item.size) }}</template>
                  </Column>
                  <Column :header="t('table.actions')">
                    <template #body="{ data: item }">
                      <Button type="button" size="small" severity="danger" outlined :label="t('action.delete')" :disabled="!!pending" @click.stop="deleteConf(item.name)" />
                    </template>
                  </Column>
                </DataTable>

                <div class="panel-body nginx-template-editor">
                  <div v-if="!creating && !selectedConf" class="empty">{{ t('supervisor.conf_pick') }}</div>
                  <template v-else>
                    <div class="nginx-template-editor-head">
                      <div>
                        <template v-if="creating">
                          <label class="supervisor-log-file">
                            {{ t('supervisor.conf_name') }}
                            <InputText v-model="newConfName" placeholder="worker.conf" fluid />
                          </label>
                        </template>
                        <template v-else>
                          <strong><code>{{ selectedConf }}</code></strong>
                          <Tag v-if="confDirty" class="home-inline-tag" :value="t('nginx.template_dirty')" severity="secondary" rounded />
                        </template>
                      </div>
                      <div class="actions">
                        <Button
                          type="button"
                          :label="pending === 'conf-save' ? t('action.working') : creating ? t('supervisor.conf_create') : t('supervisor.conf_save')"
                          :loading="pending === 'conf-save'"
                          :disabled="!!pending || confLoading || (!creating && !confDirty)"
                          @click="saveConf"
                        />
                        <Button
                          v-if="!creating && selectedConf"
                          type="button"
                          severity="danger"
                          outlined
                          :label="pending === 'conf-delete' ? t('action.working') : t('action.delete')"
                          :loading="pending === 'conf-delete'"
                          :disabled="!!pending"
                          @click="deleteConf()"
                        />
                      </div>
                    </div>
                    <p v-if="confMeta" class="nginx-template-meta">{{ formatSize(confMeta.size) }} · {{ formatTime(confMeta.updated_at) }}</p>
                    <MonacoEditor v-model="confDraft" language="ini" min-height="420px" :read-only="confLoading || pending === 'conf-save'" />
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
