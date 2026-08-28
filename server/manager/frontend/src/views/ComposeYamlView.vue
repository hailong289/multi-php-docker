<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const { t } = useI18n()
const {
  stateClass,
  stateLabel,
  composeFileState,
  composeFileActionEnabled,
  composeFileAction,
  showComposeCreateHint,
  isPending,
  loadBootstrap,
  showToast,
  translateApiError,
} = useManager()

const composeFiles = ref([])
const composeDir = ref('compose')
const defaultContent = ref('')
const filesLoading = ref(false)
const selectedName = ref('')
const draft = ref('')
const original = ref('')
const composeMeta = ref(null)
const composeLoading = ref(false)
const saving = ref(false)
const creating = ref(false)
const newFileName = ref('')
const actionLogs = ref(null)
const actionLogsLoading = ref(false)

const COMPOSE_STATUS_POLL_MS = 2000
let composeStatusPollTimer = null

const composeActionBusy = computed(() =>
  composeFiles.value.some((item) => composeFileState(item) === 'busy'),
)

function stopComposeStatusPoll() {
  if (composeStatusPollTimer) {
    clearInterval(composeStatusPollTimer)
    composeStatusPollTimer = null
  }
}

async function pollComposeStatus() {
  await Promise.all([refreshFiles(), loadBootstrap({ silent: true }), loadActionLogs({ quiet: true })])
  if (!composeActionBusy.value) {
    stopComposeStatusPoll()
  }
}

function startComposeStatusPoll() {
  stopComposeStatusPoll()
  composeStatusPollTimer = setInterval(pollComposeStatus, COMPOSE_STATUS_POLL_MS)
}

async function afterComposeAction() {
  await refreshFiles()
  await loadBootstrap({ silent: true })
  await loadActionLogs({ quiet: true })
  if (composeActionBusy.value) {
    startComposeStatusPoll()
  }
}

function actionLogStatusMessage() {
  const key = actionLogs.value?.message_key
  if (!key) return ''
  const translated = t(key)
  return translated === key ? key : translated
}

async function loadActionLogs({ quiet = false } = {}) {
  const item = selectedFile.value || composeMeta.value
  if (creating.value || !item?.name || !item?.runtime) {
    actionLogs.value = null
    return
  }
  if (!quiet) actionLogsLoading.value = true
  try {
    const result = await apiGet(
      `/api/infra-services/compose-files/${encodeURIComponent(item.name)}/action-logs`,
    )
    actionLogs.value = result.logs || null
  } catch (error) {
    if (!quiet) showToast('failure', translateApiError(error))
    actionLogs.value = null
  } finally {
    actionLogsLoading.value = false
  }
}

const dirty = computed(() => draft.value !== original.value)
const selectedFile = computed(
  () => composeFiles.value.find((item) => item.name === selectedName.value) || null,
)
const actionContext = computed(() => {
  if (creating.value) return null
  const item = selectedFile.value || composeMeta.value
  if (!item?.runtime) return null
  if (item.runtime === 'compose') return item
  if (!item.service) return null
  return {
    runtime: item.runtime,
    service: item.service,
    pullRecreate: !!item.pull_recreate,
    name: item.name,
  }
})

function formatSize(bytes) {
  const n = Number(bytes) || 0
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${(n / (1024 * 1024)).toFixed(1)} MB`
}

function formatTime(iso) {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleString()
  } catch (_) {
    return iso
  }
}

function normalizeName(raw) {
  let name = String(raw || '').trim()
  if (!name) return ''
  if (!/\.(ya?ml)$/i.test(name)) name += '.yml'
  return name
}

async function refreshFiles() {
  filesLoading.value = true
  try {
    const result = await apiGet('/api/infra-services/compose-files')
    composeFiles.value = result.files || []
    composeDir.value = result.compose_dir || 'compose'
    defaultContent.value = result.default_content || ''
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    filesLoading.value = false
  }
}

async function openFile(name) {
  if (!name) return
  if ((dirty.value || creating.value) && selectedName.value !== name) {
    if (!confirm(t('services.compose_discard_confirm'))) return
  }
  creating.value = false
  newFileName.value = ''
  selectedName.value = name
  composeLoading.value = true
  try {
    const result = await apiGet(`/api/infra-services/compose-files/${encodeURIComponent(name)}`)
    const compose = result.compose || {}
    draft.value = compose.content || ''
    original.value = draft.value
    composeMeta.value = compose
    await loadActionLogs({ quiet: true })
  } catch (error) {
    showToast('failure', translateApiError(error))
    draft.value = ''
    original.value = ''
    composeMeta.value = null
  } finally {
    composeLoading.value = false
  }
}

function startCreate() {
  if (dirty.value || creating.value) {
    if (!confirm(t('services.compose_discard_confirm'))) return
  }
  creating.value = true
  selectedName.value = ''
  newFileName.value = ''
  draft.value = defaultContent.value
  original.value = draft.value
  composeMeta.value = null
}

watch(newFileName, (name) => {
  if (!creating.value || dirty.value) return
  const normalized = normalizeName(name)
  if (!normalized || normalized === 'custom.yml') return
  draft.value = defaultContent.value.replaceAll('example', normalized.replace(/\.ya?ml$/i, ''))
  original.value = draft.value
})

async function saveCompose() {
  if (saving.value) return
  saving.value = true
  try {
    let result
    if (creating.value) {
      const name = normalizeName(newFileName.value)
      if (!/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,120}\.(yml|yaml)$/.test(name)) {
        showToast('failure', t('services.compose_invalid_name'))
        return
      }
      result = await apiSend('POST', '/api/infra-services/compose-files', {
        name,
        content: draft.value,
      })
      creating.value = false
      selectedName.value = result.compose?.name || name
    } else if (selectedName.value) {
      result = await apiSend(
        'PUT',
        `/api/infra-services/compose-files/${encodeURIComponent(selectedName.value)}`,
        { content: draft.value },
      )
    } else {
      return
    }
    original.value = draft.value
    if (result.compose) {
      composeMeta.value = { ...composeMeta.value, ...result.compose }
      selectedName.value = result.compose.name || selectedName.value
    }
    showToast('success', t(result.message_key || 'services.compose_saved'))
    await refreshFiles()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    saving.value = false
  }
}

async function deleteCompose(name) {
  const targetName = name || selectedName.value
  if (!targetName || creating.value) return
  const item = composeFiles.value.find((f) => f.name === targetName)
  if (item?.protected || item?.core) {
    showToast('failure', t('services.compose_core_protected'))
    return
  }
  if (!confirm(t('services.compose_delete_confirm', { name: targetName }))) return
  saving.value = true
  try {
    const result = await apiSend(
      'DELETE',
      `/api/infra-services/compose-files/${encodeURIComponent(targetName)}`,
      {},
    )
    showToast('success', t(result.message_key || 'services.compose_deleted'))
    if (selectedName.value === targetName) {
      selectedName.value = ''
      draft.value = ''
      original.value = ''
      composeMeta.value = null
    }
    await refreshFiles()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    saving.value = false
  }
}

async function runComposeAction(action) {
  const ctx = actionContext.value
  if (!ctx) return
  if (dirty.value && !confirm(t('services.compose_action_dirty_confirm'))) return
  const result = await composeFileAction(ctx, action)
  if (result?.compose) {
    composeMeta.value = { ...composeMeta.value, ...result.compose }
  }
  await afterComposeAction()
}

async function runComposeActionForItem(item, action) {
  if (!item?.runtime) return
  if (item.name === selectedName.value && dirty.value && !confirm(t('services.compose_action_dirty_confirm'))) {
    return
  }
  const result = await composeFileAction(item, action)
  if (result?.compose && item.name === selectedName.value) {
    composeMeta.value = { ...composeMeta.value, ...result.compose }
  }
  await afterComposeAction()
}

function composePending(item, action) {
  if (!item?.runtime) return false
  if (item.runtime === 'compose') {
    return isPending('compose-file', { name: item.name, action })
  }
  if (!item.service) return false
  return isPending(item.runtime, { service: item.service, action })
}

onMounted(async () => {
  loadBootstrap()
  await refreshFiles()
  if (!selectedName.value && !creating.value && composeFiles.value.length > 0) {
    await openFile(composeFiles.value[0].name)
  }
  if (composeActionBusy.value) {
    startComposeStatusPoll()
  }
})

onUnmounted(() => {
  stopComposeStatusPoll()
})

watch(composeActionBusy, (busy) => {
  if (busy) startComposeStatusPoll()
  else stopComposeStatusPoll()
})

watch(selectedName, () => {
  loadActionLogs({ quiet: true })
})
</script>

<template>
  <section class="panel" data-tour="compose-panel">
    <div class="panel-heading">
      <div class="controller-heading panel-heading-row">
        <div>
          <h2>{{ t('nav.compose_yaml') }}</h2>
          <p>{{ t('services.compose_hint') }}</p>
        </div>
      </div>
    </div>

    <div class="services-compose" data-tour="compose-body">
      <div class="panel-body nginx-domain-logs-toolbar">
        <p class="status-line">
          <code v-if="composeDir">{{ composeDir }}/</code>
        </p>
        <button
          type="button"
          class="primary"
          data-tour="compose-add"
          :disabled="saving || filesLoading"
          @click="startCreate"
        >
          {{ t('services.compose_add') }}
        </button>
      </div>

      <div v-if="filesLoading && composeFiles.length === 0 && !creating" class="panel-body">
        {{ t('loading') }}
      </div>
      <div v-else-if="composeFiles.length === 0 && !creating" class="panel-body empty">
        {{ t('services.compose_empty') }}
      </div>
      <div v-else class="nginx-templates-layout">
        <div class="table-wrap nginx-templates-list">
          <table>
            <thead>
              <tr>
                <th>{{ t('services.compose_name') }}</th>
                <th>{{ t('services.state') }}</th>
                <th>{{ t('table.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in composeFiles"
                :key="item.name"
                :class="{ 'is-selected': !creating && item.name === selectedName }"
                @click="openFile(item.name)"
              >
                <td>
                  <code>{{ item.name }}</code>
                  <div v-if="item.core" class="create-hint">{{ t('services.compose_core') }}</div>
                  <div v-else-if="item.protected" class="create-hint">{{ t('services.compose_managed') }}</div>
                </td>
                <td>
                  <span
                    v-if="item.runtime"
                    class="state-badge"
                    :class="stateClass(composeFileState(item))"
                  >
                    {{ stateLabel(composeFileState(item)) }}
                  </span>
                  <span v-else class="create-hint">—</span>
                  <div v-if="item.runtime === 'compose' && !item.included" class="create-hint">
                    {{ t('services.compose_not_included') }}
                  </div>
                  <div v-else-if="showComposeCreateHint(item)" class="create-hint">
                    {{ t('services.create_hint') }}
                  </div>
                </td>
                <td>
                  <div class="controller-actions" @click.stop>
                    <template v-if="item.runtime">
                      <button
                        v-if="showComposeCreateHint(item)"
                        type="button"
                        class="primary"
                        :class="{ 'is-loading': composePending(item, 'create') }"
                        :disabled="!composeFileActionEnabled(item, 'create')"
                        @click="runComposeActionForItem(item, 'create')"
                      >
                        <span
                          v-if="composePending(item, 'create')"
                          class="btn-spinner"
                          aria-hidden="true"
                        ></span>
                        {{
                          composePending(item, 'create') ? t('action.working') : t('services.create')
                        }}
                      </button>
                      <button
                        type="button"
                        :class="{ 'is-loading': composePending(item, 'start') }"
                        :disabled="!composeFileActionEnabled(item, 'start')"
                        @click="runComposeActionForItem(item, 'start')"
                      >
                        <span
                          v-if="composePending(item, 'start')"
                          class="btn-spinner"
                          aria-hidden="true"
                        ></span>
                        {{
                          composePending(item, 'start') ? t('action.working') : t('services.start')
                        }}
                      </button>
                    </template>
                    <button
                      type="button"
                      class="danger"
                      :disabled="saving || item.protected || item.core"
                      :title="item.protected || item.core ? t('services.compose_core_protected') : undefined"
                      @click="deleteCompose(item.name)"
                    >
                      {{ t('action.delete') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="panel-body nginx-template-editor" data-tour="compose-editor">
          <div v-if="!creating && !selectedName" class="empty">{{ t('services.compose_pick') }}</div>
          <template v-else>
            <div class="nginx-template-editor-head">
              <div>
                <template v-if="creating">
                  <label class="supervisor-log-file">
                    {{ t('services.compose_name') }}
                    <input v-model="newFileName" type="text" placeholder="custom.yml" />
                  </label>
                </template>
                <template v-else>
                  <strong><code>{{ selectedName }}</code></strong>
                  <span v-if="dirty" class="status-pill status-off">{{ t('nginx.template_dirty') }}</span>
                </template>
              </div>
              <div class="actions">
                <button
                  type="button"
                  class="primary"
                  :disabled="saving || composeLoading || (!creating && !dirty)"
                  @click="saveCompose"
                >
                  {{
                    saving
                      ? t('action.working')
                      : creating
                        ? t('services.compose_create')
                        : t('services.compose_save')
                  }}
                </button>
                <template v-if="actionContext?.runtime">
                  <button
                    v-if="showComposeCreateHint(actionContext)"
                    type="button"
                    class="primary"
                    :class="{ 'is-loading': composePending(actionContext, 'create') }"
                    :disabled="!composeFileActionEnabled(actionContext, 'create') || saving"
                    @click="runComposeAction('create')"
                  >
                    <span
                      v-if="composePending(actionContext, 'create')"
                      class="btn-spinner"
                      aria-hidden="true"
                    ></span>
                    {{
                      composePending(actionContext, 'create')
                        ? t('action.working')
                        : t('services.create')
                    }}
                  </button>
                  <button
                    type="button"
                    :class="{ 'is-loading': composePending(actionContext, 'start') }"
                    :disabled="!composeFileActionEnabled(actionContext, 'start') || saving"
                    @click="runComposeAction('start')"
                  >
                    <span
                      v-if="composePending(actionContext, 'start')"
                      class="btn-spinner"
                      aria-hidden="true"
                    ></span>
                    {{
                      composePending(actionContext, 'start')
                        ? t('action.working')
                        : t('services.start')
                    }}
                  </button>
                </template>
                <button
                  v-if="!creating && selectedName && !(selectedFile?.protected || selectedFile?.core)"
                  type="button"
                  class="danger"
                  :disabled="saving"
                  @click="deleteCompose()"
                >
                  {{ t('action.delete') }}
                </button>
              </div>
            </div>
            <p v-if="composeMeta && !creating" class="nginx-template-meta">
              <code>{{ composeMeta.relative_path }}</code>
              · {{ formatSize(composeMeta.size) }} · {{ formatTime(composeMeta.updated_at) }}
            </p>
            <div v-if="composeLoading" class="empty">{{ t('loading') }}</div>
            <MonacoEditor
              v-else
              v-model="draft"
              language="yaml"
              min-height="420px"
              :read-only="saving"
            />
            <div
              v-if="!creating && actionContext?.runtime"
              class="supervisor-logs compose-action-logs"
              data-tour="compose-action-logs"
            >
              <div class="nginx-domain-logs-toolbar">
                <h3>{{ t('services.compose_action_logs') }}</h3>
                <button type="button" :disabled="actionLogsLoading" @click="loadActionLogs()">
                  {{ t('services.refresh_logs') }}
                </button>
              </div>
              <p v-if="actionLogs?.updated_at" class="create-hint">
                {{ t('services.logs_updated', { at: actionLogs.updated_at }) }}
              </p>
              <p v-if="actionLogStatusMessage()" class="create-hint">
                <span
                  v-if="actionLogs?.state"
                  class="state-badge"
                  :class="stateClass(actionLogs.state)"
                >
                  {{ stateLabel(actionLogs.state) }}
                </span>
                {{ actionLogStatusMessage() }}
              </p>
              <article class="nginx-log-card">
                <pre v-if="actionLogsLoading && !actionLogs">{{ t('loading') }}</pre>
                <pre v-else>{{
                  actionLogs?.available
                    ? actionLogs.content || t('services.compose_action_logs_empty')
                    : t('services.compose_action_logs_empty')
                }}</pre>
              </article>
            </div>
          </template>
        </div>
      </div>
    </div>
  </section>
</template>
