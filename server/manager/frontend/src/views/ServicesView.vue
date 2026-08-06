<script setup>
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import TableSkeleton from '../components/TableSkeleton.vue'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const { t } = useI18n()
const {
  loading,
  data,
  infraAction,
  stateClass,
  stateLabel,
  infraServiceState,
  infraActionEnabled,
  showInfraCreateHint,
  isPending,
  loadBootstrap,
  showToast,
  translateApiError,
} = useManager()

const tab = ref('control')
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

const targets = computed(() => data.infra_services?.targets || {})
const dirty = computed(() => draft.value !== original.value)
const selectedFile = computed(
  () => composeFiles.value.find((item) => item.name === selectedName.value) || null,
)
const managedService = computed(() => {
  if (creating.value) return ''
  return selectedFile.value?.service || composeMeta.value?.service || ''
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

async function pullRecreate() {
  const service = managedService.value
  if (!service) return
  if (dirty.value && !confirm(t('services.compose_pull_dirty_confirm'))) return
  await infraAction(service, 'pull-recreate')
}

watch(tab, async (next) => {
  if (next !== 'compose') return
  await refreshFiles()
  if (!selectedName.value && !creating.value && composeFiles.value.length > 0) {
    await openFile(composeFiles.value[0].name)
  }
})

onMounted(() => {
  loadBootstrap()
})
</script>

<template>
  <section class="panel" data-tour="services-panel">
    <div class="panel-heading">
      <div class="controller-heading panel-heading-row">
        <div>
          <h2>{{ t('services.title') }}</h2>
          <p>{{ t('services.subtitle') }}</p>
        </div>
      </div>
    </div>

    <div class="panel-body php-detail-tabs-wrap" data-tour="services-tabs">
      <div class="php-detail-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          :aria-selected="tab === 'control'"
          :class="{ active: tab === 'control' }"
          data-tour="services-control-tab"
          @click="tab = 'control'"
        >
          {{ t('services.tab_control') }}
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="tab === 'compose'"
          :class="{ active: tab === 'compose' }"
          data-tour="services-compose-tab"
          @click="tab = 'compose'"
        >
          {{ t('services.tab_compose') }}
        </button>
      </div>
    </div>

    <TableSkeleton
      v-if="tab === 'control' && loading"
      :columns="5"
      :rows="3"
      :headers="[
        t('services.service'),
        t('services.container'),
        t('services.profile'),
        t('services.state'),
        t('services.actions'),
      ]"
    />
    <div v-else-if="tab === 'control'" class="table-wrap" data-tour="services-table">
      <table>
        <thead>
          <tr>
            <th>{{ t('services.service') }}</th>
            <th>{{ t('services.container') }}</th>
            <th>{{ t('services.profile') }}</th>
            <th>{{ t('services.state') }}</th>
            <th>{{ t('services.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(target, service) in targets" :key="service">
            <td>
              <div>{{ target.label }}</div>
              <div class="create-hint">{{ t('services.ports') }}: {{ target.ports }}</div>
            </td>
            <td><code>{{ target.container }}</code></td>
            <td><code>{{ target.profile }}</code></td>
            <td>
              <span class="state-badge" :class="stateClass(infraServiceState(service))">
                {{ stateLabel(infraServiceState(service)) }}
              </span>
              <div v-if="showInfraCreateHint(service, target)" class="create-hint">
                {{ t('services.create_hint') }}
              </div>
            </td>
            <td>
              <div class="controller-actions">
                <button
                  v-if="showInfraCreateHint(service, target)"
                  type="button"
                  class="primary"
                  :class="{ 'is-loading': isPending('infra', { service, action: 'create' }) }"
                  :disabled="!infraActionEnabled(service, 'create')"
                  @click="infraAction(service, 'create')"
                >
                  <span
                    v-if="isPending('infra', { service, action: 'create' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service, action: 'create' })
                      ? t('action.working')
                      : t('services.create')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service, action: 'start' }) }"
                  :disabled="!infraActionEnabled(service, 'start')"
                  @click="infraAction(service, 'start')"
                >
                  <span
                    v-if="isPending('infra', { service, action: 'start' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service, action: 'start' })
                      ? t('action.working')
                      : t('services.start')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service, action: 'stop' }) }"
                  :disabled="!infraActionEnabled(service, 'stop')"
                  @click="infraAction(service, 'stop')"
                >
                  <span
                    v-if="isPending('infra', { service, action: 'stop' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service, action: 'stop' })
                      ? t('action.working')
                      : t('services.stop')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service, action: 'restart' }) }"
                  :disabled="!infraActionEnabled(service, 'restart')"
                  @click="infraAction(service, 'restart')"
                >
                  <span
                    v-if="isPending('infra', { service, action: 'restart' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service, action: 'restart' })
                      ? t('action.working')
                      : t('services.restart')
                  }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else class="services-compose" data-tour="services-compose">
      <div class="panel-body nginx-domain-logs-toolbar">
        <p class="status-line">
          {{ t('services.compose_hint') }}
          <code v-if="composeDir">{{ composeDir }}/</code>
        </p>
        <button type="button" class="primary" data-tour="services-compose-add" :disabled="saving || filesLoading" @click="startCreate">
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
                    v-if="item.service"
                    class="state-badge"
                    :class="stateClass(infraServiceState(item.service))"
                  >
                    {{ stateLabel(infraServiceState(item.service)) }}
                  </span>
                  <span v-else class="create-hint">—</span>
                </td>
                <td>
                  <button
                    type="button"
                    class="danger"
                    :disabled="saving || item.protected"
                    :title="item.protected ? t('services.compose_core_protected') : undefined"
                    @click.stop="deleteCompose(item.name)"
                  >
                    {{ t('action.delete') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="panel-body nginx-template-editor">
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
                <button
                  v-if="managedService"
                  type="button"
                  :class="{
                    'is-loading': isPending('infra', {
                      service: managedService,
                      action: 'pull-recreate',
                    }),
                  }"
                  :disabled="!infraActionEnabled(managedService, 'pull-recreate') || saving"
                  @click="pullRecreate"
                >
                  <span
                    v-if="
                      isPending('infra', { service: managedService, action: 'pull-recreate' })
                    "
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service: managedService, action: 'pull-recreate' })
                      ? t('action.working')
                      : t('services.pull_recreate')
                  }}
                </button>
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
          </template>
        </div>
      </div>
    </div>
  </section>
</template>
