<script setup>
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { apiGet, apiSend } from '../api'
import ActionMenu from '../components/ActionMenu.vue'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const router = useRouter()
const { t } = useI18n()
const {
  stateLabel,
  composeFileState,
  composeFileAction,
  composeTabActionEnabled,
  phpActionEnabled,
  isPending,
  loadBootstrap,
  showToast,
  translateApiError,
} = useManager()

const composeFiles = ref([])
const composeDir = ref('compose')
const filesLoading = ref(false)
const selectedName = ref('')
const draft = ref('')
const original = ref('')
const composeMeta = ref(null)
const composeLoading = ref(false)
const saving = ref(false)

async function refreshAll() {
  await refreshFiles()
  await loadBootstrap({ silent: true })
}

const dirty = computed(() => draft.value !== original.value)

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}
const selectedFile = computed(
  () => composeFiles.value.find((item) => item.name === selectedName.value) || null,
)

const actionContext = computed(() => {
  const item = selectedFile.value
  if (!item?.runtime) return null
  return item
})

function showContainerRecreateButton(item) {
  if (!item?.runtime) return false
  if (item.runtime === 'php') {
    const state = composeFileState(item)
    return state === 'running' || state === 'stopped' || state === 'error'
  }
  return false
}

function showContainerDeleteButton(item) {
  if (item?.runtime !== 'php') return false
  const state = composeFileState(item)
  return state === 'running' || state === 'stopped'
}

function showContainerDeleteImageButton(item) {
  if (item?.runtime !== 'php' || !item.service) return false
  return phpActionEnabled(item.service, 'delete-image')
}

async function runContainerAction(action) {
  const item = actionContext.value
  if (!item || !action) return
  if (dirty.value && (action === 'create' || action === 'recreate')) {
    showToast('failure', t('services.compose_recreate_hint'))
    return
  }
  await composeFileAction(item, action)
  await refreshFiles()
  await loadBootstrap({ silent: true })
}

function containerActionEnabled(item, action) {
  if (!item || saving.value || composeLoading.value) return false
  if (dirty.value && (action === 'create' || action === 'recreate')) return false
  return composeTabActionEnabled(item, action)
}

function containerActionPending(item, action) {
  if (!item?.runtime || !item.service) return false
  return isPending('php', { service: item.service, action })
}

function containerMenuItems(item) {
  if (!item) return []
  return [
    {
      id: 'create',
      label: t('services.create'),
      primary: true,
      disabled: !containerActionEnabled(item, 'create'),
      loading: containerActionPending(item, 'create'),
      run: () => runContainerAction('create'),
    },
    {
      id: 'recreate',
      label: t('services.recreate'),
      hidden: !showContainerRecreateButton(item),
      disabled: !containerActionEnabled(item, 'recreate'),
      loading: containerActionPending(item, 'recreate'),
      run: () => runContainerAction('recreate'),
    },
    {
      id: 'delete',
      label: t('services.delete_container'),
      danger: true,
      hidden: !showContainerDeleteButton(item),
      disabled: !containerActionEnabled(item, 'delete'),
      loading: containerActionPending(item, 'delete'),
      run: () => runContainerAction('delete'),
    },
    {
      id: 'delete-image',
      label: t('services.delete_image'),
      danger: true,
      hidden: !showContainerDeleteImageButton(item),
      disabled: !containerActionEnabled(item, 'delete-image'),
      loading: containerActionPending(item, 'delete-image'),
      run: () => runContainerAction('delete-image'),
    },
  ]
}

const tableRows = computed(() => {
  const rows = []
  for (const item of composeFiles.value) {
    rows.push({ kind: 'file', item })
    if (item.name === selectedName.value) {
      rows.push({ kind: 'editor', item })
    }
  }
  return rows
})

function tableRowKey(row) {
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

async function refreshFiles() {
  filesLoading.value = true
  try {
    const result = await apiGet('/api/infra-services/compose-files?scope=php')
    composeFiles.value = result.files || []
    composeDir.value = result.compose_dir || 'compose'
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    filesLoading.value = false
  }
}

async function openFile(name) {
  if (!name) return
  if (dirty.value && selectedName.value !== name) {
    if (!(await confirmDialog(t('services.compose_discard_confirm'), { acceptLabel: t('action.ok'), rejectLabel: t('action.cancel'), acceptSeverity: 'primary' }))) return
  }
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

async function saveCompose() {
  if (saving.value || !selectedName.value || !dirty.value) return
  saving.value = true
  try {
    const result = await apiSend(
      'PUT',
      `/api/infra-services/compose-files/${encodeURIComponent(selectedName.value)}`,
      { content: draft.value },
    )
    original.value = draft.value
    if (result.compose) {
      composeMeta.value = { ...composeMeta.value, ...result.compose }
      selectedName.value = result.compose.name || selectedName.value
    }
    showToast('success', t(result.message_key || 'services.compose_saved'))
    await refreshFiles()
    await loadBootstrap({ silent: true })
    const item = selectedFile.value
    if (item && showContainerRecreateButton(item)) {
      if (await confirmDialog(t('services.compose_save_recreate_confirm'), { acceptLabel: t('action.ok'), rejectLabel: t('action.cancel'), acceptSeverity: 'primary' })) {
        await runContainerAction('recreate')
      }
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  loadBootstrap()
  await refreshFiles()
  if (!selectedName.value && composeFiles.value.length > 0) {
    await openFile(composeFiles.value[0].name)
  }
})
</script>

<template>
  <section class="panel compose-yaml-page" data-tour="php-compose-panel">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
                <Button
          type="button"
          class="icon-back"
          icon="pi pi-arrow-left"
          severity="secondary"
          text
          rounded
          :aria-label="t('php_controller.back_to_versions')"
          :title="t('php_controller.back_to_versions')"
          @click="router.push({ name: 'php-versions' })"
        />
        <div>
          <h2>{{ t('php_controller.manage_yaml') }}</h2>
          <p>{{ t('php_controller.yaml_hint') }}</p>
        </div>
      </div>
      <div class="panel-heading-actions">
        <Button type="button" severity="secondary" outlined :label="t('nginx.refresh')" :disabled="saving || filesLoading" @click="refreshAll" />
      </div>
    </div>

    <div class="services-compose compose-yaml-body" data-tour="php-compose-body">
      <div v-if="filesLoading && composeFiles.length === 0" class="panel-body">
        {{ t('loading') }}
      </div>
      <div v-else-if="composeFiles.length === 0" class="panel-body empty">
        {{ t('php_controller.yaml_empty') }}
      </div>
      <div v-else class="table-wrap compose-yaml-list">
        <div v-if="composeDir" class="compose-yaml-dir">
          <p class="status-line">
            <code>{{ composeDir }}/php-*.yml</code>
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
            <template v-for="row in tableRows" :key="tableRowKey(row)">
              <tr
                v-if="row.kind === 'file'"
                :class="{
                  'is-selected': row.item.name === selectedName,
                  'is-busy': composeFileState(row.item) === 'busy',
                }"
                @click="openFile(row.item.name)"
              >
                <td>
                  <code>{{ row.item.name }}</code>
                  <div class="create-hint">{{ t('services.compose_managed') }}</div>
                </td>
                <td>
                  <Tag
                    v-if="row.item.runtime"
                    :value="stateLabel(composeFileState(row.item))"
                    :severity="stateSeverity(composeFileState(row.item))"
                    rounded
                  />
                  <span v-else class="create-hint">—</span>
                </td>
              </tr>
              <tr v-else-if="row.kind === 'editor'" class="compose-yaml-editor-row">
                <td colspan="2">
                  <div class="compose-yaml-editor-panel" data-tour="php-compose-editor">
                    <div class="nginx-template-editor-head compose-yaml-editor-head">
                      <div class="compose-yaml-title">
                        <strong><code>{{ selectedName }}</code></strong>
                        <Tag v-if="dirty" class="home-inline-tag" :value="t('nginx.template_dirty')" severity="secondary" rounded />
                        <p v-if="composeMeta" class="nginx-template-meta">
                          <code>{{ composeMeta.relative_path }}</code>
                          · {{ formatSize(composeMeta.size) }} · {{ formatTime(composeMeta.updated_at) }}
                        </p>
                      </div>
                      <div class="actions controller-actions">
                        <Button
                          type="button"
                          size="small"
                          :label="saving ? t('action.working') : t('services.compose_save')"
                          :loading="saving"
                          :disabled="saving || composeLoading || !dirty"
                          @click.stop="saveCompose"
                        />
                        <ActionMenu v-if="actionContext" :items="containerMenuItems(actionContext)" />
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
        <p v-if="!selectedName && composeFiles.length > 0" class="compose-yaml-pick panel-body empty">
          {{ t('services.compose_pick') }}
        </p>
      </div>
    </div>
  </section>
</template>
