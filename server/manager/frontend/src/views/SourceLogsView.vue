<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import { apiGet, apiRelativeUrl, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'
import { FRAMEWORK_PRESETS, LOG_PRESETS } from '../lib/frameworkPaths'
import { highlightLog } from '../lib/logHighlight'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { showToast, translateApiError } = useManager()

const serverKey = computed(() => String(route.params.serverKey || ''))
const source = ref(null)
const loading = ref(false)
const selectedFile = ref('')
const log = ref(null)
const detailLoading = ref(false)
const followLogs = ref(false)
const editing = ref(false)
const editorDraft = ref('')
const editorOriginal = ref('')
const pending = ref('')
const frameworkDraft = ref('laravel')
const logPathDraft = ref('')
const fieldErrors = ref({})
const logPre = ref(null)
/** @type {EventSource|null} */
let followSource = null

const frameworkOptions = computed(() =>
  FRAMEWORK_PRESETS.map((item) => ({
    label: t(`form.framework_${item.id}`),
    value: item.id,
  })),
)

const logFiles = computed(() => source.value?.files || [])
const editorDirty = computed(() => editing.value && editorDraft.value !== editorOriginal.value)

const presetPath = computed(() => LOG_PRESETS[frameworkDraft.value] || '')
const customPath = computed(() =>
  String(logPathDraft.value || '')
    .trim()
    .replace(/^\/+|\/+$/g, ''),
)

const pageSubtitle = computed(() => {
  const item = source.value
  if (!item) return ''
  return [item.app_name, item.domain_name].filter(Boolean).join(' · ')
})

const logHtml = computed(() => {
  if (log.value?.available) {
    return log.value.content ? highlightLog(log.value.content) : highlightLog(t('source_logs.log_empty'))
  }
  if (selectedFile.value) return highlightLog(t('source_logs.missing'))
  return highlightLog(t('source_logs.unset'))
})

const resolvedPreview = computed(() => {
  const project = source.value?.project_dir || ''
  if (!project) return ''
  const relative = String(logPathDraft.value || '')
    .trim()
    .replace(/^\/+|\/+$/g, '')
  const path = relative || presetPath.value
  return path ? `${project}/${path}` : ''
})

function goHome() {
  router.push({ name: 'home' })
}

function formatSize(bytes) {
  const size = Number(bytes) || 0
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function formatTime(iso) {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function applyFieldErrors(error) {
  const fields = error?.payload?.error?.fields || {}
  const next = {}
  for (const [name, item] of Object.entries(fields)) {
    if (item?.key) next[name] = t(item.key, item.parameters || {})
  }
  fieldErrors.value = next
  return Object.keys(next).length > 0
}

function stopFollow() {
  if (followSource) {
    followSource.close()
    followSource = null
  }
}

function applyFollow(payload, mode) {
  const prev = log.value || { available: true, content: '', updated_at: '', size: 0 }
  const content = mode === 'append' ? `${prev.content || ''}${payload.content || ''}` : payload.content || ''
  log.value = {
    available: true,
    content,
    updated_at: payload.updated_at || prev.updated_at || '',
    size: payload.size ?? prev.size ?? 0,
  }
  if (source.value && selectedFile.value && payload.size != null) {
    source.value = {
      ...source.value,
      files: (source.value.files || []).map((file) =>
        file.name === selectedFile.value
          ? { ...file, size: payload.size, updated_at: payload.updated_at || file.updated_at }
          : file,
      ),
    }
  }
  scrollLogs()
}

async function scrollLogs() {
  await nextTick()
  const el = logPre.value
  if (el) el.scrollTop = el.scrollHeight
}

function stopEditing() {
  editing.value = false
  editorDraft.value = ''
  editorOriginal.value = ''
}

async function loadLog({ silent = false } = {}) {
  if (!serverKey.value || !selectedFile.value || editing.value) return
  if (!silent) detailLoading.value = true
  try {
    const result = await apiGet(
      `/api/sources/${encodeURIComponent(serverKey.value)}/logs?file=${encodeURIComponent(selectedFile.value)}`,
    )
    log.value = result.log || null
    if (result.source?.key === serverKey.value) source.value = result.source
    if (followLogs.value) await scrollLogs()
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    if (!silent) detailLoading.value = false
  }
}

function chooseFile(next, preferred = '') {
  const names = (next.files || []).map((file) => file.name)
  if (preferred && names.includes(preferred)) return preferred
  return names[0] || ''
}

async function openSource(next, { resetDraft = false, preferredFile = '' } = {}) {
  source.value = next
  if (resetDraft) {
    frameworkDraft.value = next.framework || 'custom'
    logPathDraft.value = next.log_path || ''
    fieldErrors.value = {}
    followLogs.value = false
    stopFollow()
    stopEditing()
  }
  const nextFile = chooseFile(next, preferredFile)
  selectedFile.value = nextFile
  log.value = null
  if (nextFile) await loadLog()
}

async function loadSource() {
  const key = serverKey.value
  if (!/^SERVER_NAME\d+$/.test(key)) {
    showToast('failure', t('source_logs.not_found'))
    goHome()
    return
  }
  loading.value = true
  source.value = null
  log.value = null
  selectedFile.value = ''
  try {
    const result = await apiGet('/api/sources/logs')
    const found = (result.sources || []).find((item) => item.key === key)
    if (!found) {
      showToast('failure', t('source_logs.not_found'))
      goHome()
      return
    }
    await openSource(found, { resetDraft: true })
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    loading.value = false
  }
}

function onFollowChange(value) {
  followLogs.value = !!value
  stopFollow()
  if (!followLogs.value || !selectedFile.value || editing.value) return
  const url = apiRelativeUrl(
    `/api/sources/${encodeURIComponent(serverKey.value)}/logs/stream?file=${encodeURIComponent(selectedFile.value)}`,
  )
  const stream = new EventSource(url, { withCredentials: true })
  followSource = stream
  const take = (mode) => (event) => {
    try {
      applyFollow(JSON.parse(event.data), mode)
    } catch (_) {}
  }
  stream.addEventListener('snapshot', take('replace'))
  stream.addEventListener('reset', take('replace'))
  stream.addEventListener('append', take('append'))
  stream.addEventListener('gone', () => {
    log.value = { available: false, content: '', updated_at: '', size: 0 }
    followLogs.value = false
    stopFollow()
  })
  stream.addEventListener('reconnect', () => {
    const still = followLogs.value
    stopFollow()
    if (still) onFollowChange(true)
  })
}

async function onFileChange(name) {
  if (!name || name === selectedFile.value) return
  if (
    editorDirty.value &&
    !(await confirmDialog(t('source_logs.discard_confirm'), {
      acceptLabel: t('action.ok'),
      rejectLabel: t('action.cancel'),
    }))
  ) {
    return
  }
  stopEditing()
  selectedFile.value = name
  log.value = null
  await loadLog()
}

async function startEdit() {
  if (!serverKey.value || !selectedFile.value) return
  followLogs.value = false
  stopFollow()
  pending.value = 'edit'
  try {
    const result = await apiGet(
      `/api/sources/${encodeURIComponent(serverKey.value)}/logs?file=${encodeURIComponent(selectedFile.value)}&full=1`,
    )
    editorDraft.value = result.log?.content || ''
    editorOriginal.value = editorDraft.value
    log.value = result.log || null
    if (result.source?.key === serverKey.value) source.value = result.source
    editing.value = true
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

function cancelEdit() {
  stopEditing()
  loadLog()
}

async function saveFile() {
  if (!source.value || !selectedFile.value) return
  pending.value = 'write'
  try {
    const result = await apiSend('PUT', `/api/sources/${encodeURIComponent(source.value.key)}/logs`, {
      file: selectedFile.value,
      content: editorDraft.value,
    })
    stopEditing()
    log.value = result.log || null
    if (result.source?.key === serverKey.value) source.value = result.source
    showToast('success', t(result.message_key || 'source_logs.saved'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function deleteFile() {
  if (!source.value || !selectedFile.value) return
  const file = selectedFile.value
  if (
    !(await confirmDialog(t('source_logs.delete_confirm', { file }), {
      acceptLabel: t('action.delete'),
      rejectLabel: t('action.cancel'),
      acceptSeverity: 'danger',
    }))
  ) {
    return
  }
  pending.value = 'delete'
  followLogs.value = false
  stopFollow()
  try {
    const result = await apiSend('DELETE', `/api/sources/${encodeURIComponent(source.value.key)}/logs`, {
      file,
    })
    stopEditing()
    const next = result.source?.key === serverKey.value ? result.source : source.value
    source.value = next
    showToast('success', t(result.message_key || 'source_logs.deleted'))
    const nextFile = chooseFile(next)
    selectedFile.value = nextFile
    log.value = null
    if (nextFile) await loadLog()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function useDefaultPath() {
  logPathDraft.value = ''
  await saveConfig()
}

async function saveConfig() {
  if (!source.value) return
  pending.value = 'save'
  fieldErrors.value = {}
  try {
    const result = await apiSend('PUT', `/api/sources/${encodeURIComponent(source.value.key)}/log-config`, {
      framework: frameworkDraft.value,
      log_path: customPath.value,
    })
    showToast('success', t(result.message_key || 'source_logs.config_saved'))
    if (result.source) {
      await openSource(result.source, { resetDraft: true, preferredFile: selectedFile.value })
    }
  } catch (error) {
    if (!applyFieldErrors(error)) showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function clearLog() {
  if (!source.value || !selectedFile.value) return
  if (
    !(await confirmDialog(t('source_logs.clear_confirm', { file: selectedFile.value }), {
      acceptLabel: t('action.delete'),
      rejectLabel: t('action.cancel'),
      acceptSeverity: 'danger',
    }))
  ) {
    return
  }
  pending.value = 'clear'
  try {
    const result = await apiSend('POST', `/api/sources/${encodeURIComponent(source.value.key)}/logs/clear`, {
      file: selectedFile.value,
    })
    log.value = result.log || null
    if (result.source?.key === serverKey.value) source.value = result.source
    showToast('success', t(result.message_key || 'source_logs.cleared'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

watch(selectedFile, () => {
  if (followLogs.value) onFollowChange(true)
})

watch(serverKey, () => {
  loadSource()
}, { immediate: true })

onUnmounted(() => {
  stopFollow()
})
</script>

<template>
  <section class="panel source-logs-page" data-tour="source-logs-panel">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <Button
          type="button"
          class="icon-back"
          icon="pi pi-arrow-left"
          severity="secondary"
          text
          rounded
          :aria-label="t('source_logs.back')"
          :title="t('source_logs.back')"
          @click="goHome"
        />
        <div>
          <h2>{{ t('source_logs.title') }}</h2>
          <p v-if="pageSubtitle">{{ pageSubtitle }}</p>
          <p v-else class="status-line">{{ t('source_logs.hint') }}</p>
        </div>
      </div>
      <div v-if="source" class="panel-heading-actions">
        <label class="follow-toggle" for="source-follow-logs">
          <ToggleSwitch
            :model-value="followLogs"
            input-id="source-follow-logs"
            :disabled="!selectedFile"
            @update:model-value="onFollowChange"
          />
          <span>{{ t('services.follow_logs') }}</span>
        </label>
        <Button
          type="button"
          size="small"
          :label="t('services.refresh_logs')"
          :disabled="detailLoading || !!pending || !selectedFile"
          :loading="detailLoading"
          @click="loadLog()"
        />
        <Button
          type="button"
          size="small"
          severity="danger"
          outlined
          :label="pending === 'clear' ? t('action.working') : t('source_logs.clear')"
          :loading="pending === 'clear'"
          :disabled="detailLoading || !!pending || !selectedFile || !log?.available"
          @click="clearLog"
        />
      </div>
    </div>

    <div class="panel-body">
      <div v-if="loading && !source" class="nginx-tab-pad">{{ t('loading') }}</div>
      <template v-else-if="source">
        <div class="nginx-template-title source-log-tags">
          <Tag :value="t(`form.framework_${source.framework}`)" severity="secondary" rounded />
          <span v-if="!source.framework_stored" class="nginx-template-meta">
            {{ t('source_logs.detected') }}
          </span>
        </div>

        <div class="source-log-config">
          <label>{{ t('source_logs.framework') }}</label>
          <Select
            v-model="frameworkDraft"
            :options="frameworkOptions"
            option-label="label"
            option-value="value"
            fluid
          />
          <small v-if="fieldErrors.framework" class="p-error">{{ fieldErrors.framework }}</small>

          <label>{{ t('source_logs.path') }}</label>
          <InputText
            v-model="logPathDraft"
            :placeholder="t('source_logs.path_placeholder')"
            autocomplete="off"
            fluid
          />
          <p class="form-path-hint">
            {{
              presetPath
                ? t('source_logs.preset', { path: presetPath })
                : t('source_logs.preset_none')
            }}
          </p>
          <small v-if="fieldErrors.log_path" class="p-error">{{ fieldErrors.log_path }}</small>
          <div class="source-log-config-actions">
            <Button
              type="button"
              size="small"
              :label="pending === 'save' ? t('action.working') : t('source_logs.save')"
              :loading="pending === 'save'"
              :disabled="!!pending"
              @click="saveConfig"
            />
            <Button
              v-if="customPath || source.log_path"
              type="button"
              size="small"
              severity="secondary"
              outlined
              :label="t('source_logs.use_default')"
              :disabled="!!pending"
              @click="useDefaultPath"
            />
          </div>
        </div>

        <div class="source-log-folder">
          <span class="source-log-folder-label">{{ t('source_logs.folder') }}</span>
          <code v-if="resolvedPreview">{{ resolvedPreview }}</code>
          <span v-else class="nginx-template-meta">{{ t('source_logs.path_unset') }}</span>
          <span class="source-log-folder-note">
            {{
              customPath
                ? t('source_logs.using_custom')
                : presetPath
                  ? t('source_logs.using_default')
                  : t('source_logs.preset_none')
            }}
          </span>
        </div>

        <p v-if="source.kind === 'unset'" class="status-line">{{ t('source_logs.unset') }}</p>
        <p v-else-if="source.kind === 'missing'" class="status-line">
          {{ t('source_logs.missing') }}
          <code v-if="source.resolved">{{ source.resolved }}</code>
        </p>
        <div v-else class="source-log-browser">
          <aside class="source-log-files">
            <div class="source-log-files-head">
              <span>{{ t('source_logs.files') }}</span>
              <span class="source-log-files-count">{{ logFiles.length }}</span>
            </div>
            <p v-if="logFiles.length === 0" class="source-log-files-empty">{{ t('source_logs.files_empty') }}</p>
            <ul v-else>
              <li v-for="file in logFiles" :key="file.name">
                <button
                  type="button"
                  :class="{ 'is-selected': file.name === selectedFile }"
                  :aria-current="file.name === selectedFile ? 'true' : undefined"
                  @click="onFileChange(file.name)"
                >
                  <span class="source-log-file-name">
                    <i class="pi pi-file" aria-hidden="true" />
                    {{ file.name }}
                  </span>
                  <span class="source-log-file-meta">
                    {{ formatSize(file.size) }}
                    <template v-if="formatTime(file.updated_at)"> · {{ formatTime(file.updated_at) }}</template>
                  </span>
                </button>
              </li>
            </ul>
          </aside>
          <div class="source-log-viewer">
            <div class="source-log-viewer-actions">
              <Button
                v-if="!editing"
                type="button"
                size="small"
                :label="pending === 'edit' ? t('action.working') : t('source_logs.edit')"
                :loading="pending === 'edit'"
                :disabled="!!pending || !selectedFile"
                @click="startEdit"
              />
              <template v-else>
                <Button
                  type="button"
                  size="small"
                  :label="pending === 'write' ? t('action.working') : t('source_logs.save_file')"
                  :loading="pending === 'write'"
                  :disabled="!!pending || !editorDirty"
                  @click="saveFile"
                />
                <Button
                  type="button"
                  size="small"
                  severity="secondary"
                  outlined
                  :label="t('source_logs.cancel_edit')"
                  :disabled="!!pending"
                  @click="cancelEdit"
                />
              </template>
              <Button
                type="button"
                size="small"
                severity="danger"
                outlined
                :label="pending === 'delete' ? t('action.working') : t('source_logs.delete')"
                :loading="pending === 'delete'"
                :disabled="!!pending || !selectedFile"
                @click="deleteFile"
              />
            </div>
            <textarea
              v-if="editing"
              v-model="editorDraft"
              class="source-log-editor"
              spellcheck="false"
            />
            <pre
              v-else-if="detailLoading && !log"
              ref="logPre"
              class="service-logs-pre source-logs-pre"
            >{{ t('loading') }}</pre>
            <pre
              v-else
              ref="logPre"
              class="service-logs-pre source-logs-pre"
              v-html="logHtml"
            />
            <p v-if="log?.updated_at" class="nginx-template-meta">
              {{ log.updated_at }}
              <template v-if="log.size != null"> · {{ formatSize(log.size) }}</template>
            </p>
          </div>
        </div>
      </template>
    </div>
  </section>
</template>
