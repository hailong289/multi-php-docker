<script setup>
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const router = useRouter()
const { t } = useI18n()
const {
  stateClass,
  stateLabel,
  composeFileState,
  composeFileAction,
  composeTabActionEnabled,
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

async function refreshAll() {
  await refreshFiles()
  await loadBootstrap({ silent: true })
}

const dirty = computed(() => draft.value !== original.value)
const selectedFile = computed(
  () => composeFiles.value.find((item) => item.name === selectedName.value) || null,
)

const actionContext = computed(() => {
  if (creating.value) return null
  const item = selectedFile.value
  if (!item?.runtime) return null
  return item
})

const containerRecreateAction = computed(() => {
  const item = actionContext.value
  if (!item) return null
  if (item.runtime === 'compose') return 'recreate'
  if (item.pull_recreate && item.runtime === 'infra') return 'pull-recreate'
  return null
})

function showContainerCreateButton(item) {
  return showComposeCreateHint(item)
}

function showContainerRecreateButton(item) {
  if (!item?.runtime) return false
  if (item.runtime === 'compose') {
    const state = composeFileState(item)
    return state === 'running' || state === 'stopped' || state === 'error'
  }
  if (item.pull_recreate && item.runtime === 'infra') {
    const state = composeFileState(item)
    return state === 'running' || state === 'stopped'
  }
  return false
}

function containerActionEnabled(item, action) {
  if (!item || dirty.value || saving.value || composeLoading.value) return false
  return composeTabActionEnabled(item, action)
}

function containerActionPending(item, action) {
  if (!item?.runtime) return false
  if (item.runtime === 'compose') {
    return isPending('compose-file', { name: item.name, action })
  }
  if (item.runtime === 'infra') {
    return isPending('infra', { service: item.service, action })
  }
  if (item.runtime === 'supervisor') {
    return isPending('supervisor', { service: item.service, action })
  }
  return false
}

async function runContainerAction(action) {
  const item = actionContext.value
  if (!item || !action) return
  if (dirty.value) {
    showToast('failure', t('services.compose_recreate_hint'))
    return
  }
  await composeFileAction(item, action)
  await refreshFiles()
  await loadBootstrap({ silent: true })
}

const tableRows = computed(() => {
  const rows = []
  if (creating.value) {
    rows.push({ kind: 'creating' })
    rows.push({ kind: 'editor' })
    return rows
  }
  for (const item of composeFiles.value) {
    rows.push({ kind: 'file', item })
    if (item.name === selectedName.value) {
      rows.push({ kind: 'editor', item })
    }
  }
  return rows
})

function tableRowKey(row, index) {
  if (row.kind === 'creating') return 'creating'
  if (row.kind === 'editor') {
    return `editor:${row.item?.name || 'new'}`
  }
  return `file:${row.item.name}`
}

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
    await loadBootstrap({ silent: true })
    const item = selectedFile.value
    if (!creating.value && item && showContainerRecreateButton(item)) {
      if (confirm(t('services.compose_save_recreate_confirm'))) {
        await runContainerAction('recreate')
      }
    }
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
    await loadBootstrap({ silent: true })
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  loadBootstrap()
  await refreshFiles()
  if (!selectedName.value && !creating.value && composeFiles.value.length > 0) {
    await openFile(composeFiles.value[0].name)
  }
})
</script>

<template>
  <section class="panel compose-yaml-page" data-tour="compose-panel">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <button
          type="button"
          class="icon-back"
          :aria-label="t('services.back_to_services')"
          :title="t('services.back_to_services')"
          @click="router.push({ name: 'services' })"
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
          <h2>{{ t('services.manage_compose_yaml') }}</h2>
          <p>{{ t('services.compose_hint') }}</p>
        </div>
      </div>
      <div class="panel-heading-actions">
          <button
            type="button"
            :disabled="saving || filesLoading"
            @click="refreshAll"
          >
            {{ t('nginx.refresh') }}
          </button>
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
    </div>

    <div class="services-compose compose-yaml-body" data-tour="compose-body">
      <div v-if="filesLoading && composeFiles.length === 0 && !creating" class="panel-body">
        {{ t('loading') }}
      </div>
      <div v-else-if="composeFiles.length === 0 && !creating" class="panel-body empty">
        {{ t('services.compose_empty') }}
      </div>
      <div v-else class="table-wrap compose-yaml-list">
        <div v-if="composeDir" class="compose-yaml-dir">
          <p class="status-line">
            <code>{{ composeDir }}/</code>
          </p>
        </div>
        <table>
            <thead>
              <tr>
                <th>{{ t('services.compose_name') }}</th>
                <th>{{ t('services.state') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(row, index) in tableRows" :key="tableRowKey(row, index)">
                <tr
                  v-if="row.kind === 'creating'"
                  class="is-selected is-creating"
                >
                  <td colspan="2">
                    <code>{{ newFileName || t('services.compose_create') }}</code>
                    <span class="status-pill status-off">{{ t('nginx.template_dirty') }}</span>
                  </td>
                </tr>
                <tr
                  v-else-if="row.kind === 'file'"
                  :class="{
                    'is-selected': row.item.name === selectedName,
                    'is-busy': composeFileState(row.item) === 'busy',
                  }"
                  @click="openFile(row.item.name)"
                >
                  <td>
                    <code>{{ row.item.name }}</code>
                    <div v-if="row.item.core" class="create-hint">{{ t('services.compose_core') }}</div>
                    <div v-else-if="row.item.protected" class="create-hint">{{ t('services.compose_managed') }}</div>
                    <div
                      v-else-if="row.item.runtime === 'compose' && !row.item.included"
                      class="create-hint status-line warn"
                    >
                      {{ t('services.compose_not_included') }}
                    </div>
                  </td>
                  <td>
                    <span
                      v-if="row.item.runtime"
                      class="state-badge"
                      :class="stateClass(composeFileState(row.item))"
                    >
                      {{ stateLabel(composeFileState(row.item)) }}
                    </span>
                    <span v-else class="create-hint">—</span>
                  </td>
                </tr>
                <tr v-else-if="row.kind === 'editor'" class="compose-yaml-editor-row">
                  <td colspan="2">
                    <div class="compose-yaml-editor-panel" data-tour="compose-editor">
                      <div class="nginx-template-editor-head compose-yaml-editor-head">
                        <div class="compose-yaml-title">
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
                          <p v-if="composeMeta && !creating" class="nginx-template-meta">
                            <code>{{ composeMeta.relative_path }}</code>
                            · {{ formatSize(composeMeta.size) }} · {{ formatTime(composeMeta.updated_at) }}
                          </p>
                        </div>
                        <div class="actions controller-actions">
                          <button
                            type="button"
                            class="primary"
                            :disabled="saving || composeLoading || (!creating && !dirty)"
                            @click.stop="saveCompose"
                          >
                            <span v-if="saving" class="btn-spinner" aria-hidden="true"></span>
                            {{
                              saving
                                ? t('action.working')
                                : creating
                                  ? t('services.compose_create')
                                  : t('services.compose_save')
                            }}
                          </button>
                          <button
                            v-if="!creating && selectedName && !(selectedFile?.protected || selectedFile?.core)"
                            type="button"
                            class="danger"
                            :disabled="saving"
                            @click.stop="deleteCompose()"
                          >
                            {{ t('services.compose_delete_file') }}
                          </button>
                          <button
                            v-if="!creating && actionContext && showContainerCreateButton(actionContext)"
                            type="button"
                            class="primary"
                            :class="{ 'is-loading': containerActionPending(actionContext, 'create') }"
                            :disabled="!containerActionEnabled(actionContext, 'create')"
                            @click.stop="runContainerAction('create')"
                          >
                            <span
                              v-if="containerActionPending(actionContext, 'create')"
                              class="btn-spinner"
                              aria-hidden="true"
                            ></span>
                            {{
                              containerActionPending(actionContext, 'create')
                                ? t('action.working')
                                : t('services.create')
                            }}
                          </button>
                          <button
                            v-if="!creating && actionContext && showContainerRecreateButton(actionContext)"
                            type="button"
                            :class="{ 'is-loading': containerActionPending(actionContext, containerRecreateAction) }"
                            :disabled="!containerActionEnabled(actionContext, containerRecreateAction)"
                            @click.stop="runContainerAction(containerRecreateAction)"
                          >
                            <span
                              v-if="containerActionPending(actionContext, containerRecreateAction)"
                              class="btn-spinner"
                              aria-hidden="true"
                            ></span>
                            {{
                              containerActionPending(actionContext, containerRecreateAction)
                                ? t('action.working')
                                : actionContext.pull_recreate && actionContext.runtime === 'infra'
                                  ? t('services.pull_recreate')
                                  : t('services.recreate')
                            }}
                          </button>
                        </div>
                      </div>

                      <div>
                        <div v-if="composeLoading" class="empty">{{ t('loading') }}</div>
                        <MonacoEditor
                          v-else
                          v-model="draft"
                          language="yaml"
                          min-height="420px"
                          :read-only="saving"
                        />
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          <p v-if="!creating && !selectedName && composeFiles.length > 0" class="compose-yaml-pick panel-body empty">
            {{ t('services.compose_pick') }}
          </p>
        </div>
    </div>
  </section>
</template>
