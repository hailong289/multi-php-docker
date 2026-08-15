<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import TableSkeleton from '../components/TableSkeleton.vue'
import { useManager } from '../composables/useManager'
import {
  FRAMEWORK_PRESETS,
  buildDocRoot,
  buildProjectDir,
  buildServerPath,
  detectFramework,
  joinServerPath,
  splitServerPath,
} from '../lib/frameworkPaths'

const { t } = useI18n()
const router = useRouter()
const {
  loading,
  busy,
  modalOpen,
  editingKey,
  fieldErrors,
  data,
  form,
  serverEntries,
  versionLabel,
  openAddModal,
  closeModal,
  startEdit,
  saveServer,
  deleteServer,
  toggleServerEnabled,
  isServerEnabled,
  reloadNginx,
  nginxStatusText,
  nginxStatusOk,
  isPending,
} = useManager()

const frameworkId = ref('laravel')
const pathTouched = ref(false)
const projectDir = ref('')
const docRoot = ref('public')

function openTerminal(item) {
  router.push({ name: 'terminal', params: { serverKey: item.key } })
}

const sourcePrefix = computed(
  () => data.php_versions?.[form.php_version]?.source_prefix || '/var/www/source_php8.5',
)

const frameworkHint = computed(() => {
  const key = `form.framework_hint.${frameworkId.value}`
  const translated = t(key)
  return translated === key ? t('form.framework_hint.custom') : translated
})

const pathExample = computed(() => {
  const joined = joinServerPath(projectDir.value, docRoot.value)
  if (joined) return joined
  if (frameworkId.value === 'custom') return form.server_path || '—'
  return buildServerPath(sourcePrefix.value, form.app_name, frameworkId.value) || '—'
})

const hostExample = computed(() => {
  const joined = joinServerPath(projectDir.value, docRoot.value)
  const container =
    joined ||
    (frameworkId.value === 'custom'
      ? form.server_path
      : buildServerPath(sourcePrefix.value, form.app_name, frameworkId.value)) ||
    ''
  return String(container).replace(/^\/var\/www\//, '') || '—'
})

function syncServerPathFromParts() {
  form.server_path = joinServerPath(projectDir.value, docRoot.value)
}

function applyFrameworkPath({ force = false } = {}) {
  if (frameworkId.value === 'custom') return
  if (pathTouched.value && !force) return
  projectDir.value = buildProjectDir(sourcePrefix.value, form.app_name)
  docRoot.value = buildDocRoot(frameworkId.value)
  syncServerPathFromParts()
}

function onFrameworkChange() {
  pathTouched.value = false
  applyFrameworkPath({ force: true })
}

function onPathPartsInput() {
  pathTouched.value = true
  frameworkId.value = 'custom'
  syncServerPathFromParts()
}

function openAdd() {
  frameworkId.value = 'laravel'
  pathTouched.value = false
  openAddModal()
  applyFrameworkPath({ force: true })
}

function openEdit(key) {
  startEdit(key)
  frameworkId.value = detectFramework(form.server_path, sourcePrefix.value)
  pathTouched.value = frameworkId.value === 'custom'
  const parts = splitServerPath(form.server_path)
  projectDir.value = parts.projectDir
  docRoot.value = parts.docRoot
}

watch(modalOpen, (open) => {
  if (!open) {
    frameworkId.value = 'laravel'
    pathTouched.value = false
    projectDir.value = ''
    docRoot.value = 'public'
  }
})

watch(
  [() => form.app_name, () => form.php_version, frameworkId],
  () => {
    if (!modalOpen.value) return
    applyFrameworkPath()
  },
)
</script>

<template>
  <section class="panel" data-tour="home-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <h2>{{ $t('servers.title') }}</h2>
        <div class="panel-heading-actions">
          <button
            type="button"
            class="primary"
            data-tour="home-add"
            :disabled="busy || loading"
            @click="openAdd"
          >
            {{ $t('form.add') }}
          </button>
          <button
            type="button"
            data-tour="home-reload"
            :class="{ 'is-loading': isPending('reload') }"
            :disabled="busy || loading"
            @click="reloadNginx"
          >
            <span v-if="isPending('reload')" class="btn-spinner" aria-hidden="true"></span>
            {{ isPending('reload') ? $t('action.working') : $t('reload.button') }}
          </button>
        </div>
      </div>
      <p v-if="!loading && data.nginx_status" class="status-line">
        <strong>
          {{ nginxStatusOk() ? $t('reload.success') : $t('reload.error') }}:
        </strong>
        {{ nginxStatusText() }}
      </p>
      <div v-else-if="loading" class="status-line">
        <span class="skeleton-line skeleton-w2"></span>
      </div>
    </div>

    <TableSkeleton
      v-if="loading"
      data-tour="home-table"
      :columns="4"
      :rows="4"
      :headers="[
        $t('table.app_domain'),
        $t('table.php'),
        $t('table.document_root'),
        $t('table.actions'),
      ]"
    />
    <div v-else-if="serverEntries.length === 0" class="empty" data-tour="home-table">{{ $t('servers.empty') }}</div>
    <div v-else class="table-wrap" data-tour="home-table">
      <table class="servers-table">
        <thead>
          <tr>
            <th>{{ $t('table.app_domain') }}</th>
            <th>{{ $t('table.php') }}</th>
            <th>{{ $t('table.document_root') }}</th>
            <th>{{ $t('table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="item in serverEntries"
            :key="item.key"
            :class="{ 'is-disabled': !isServerEnabled(item.server) }"
          >
            <td>
              <strong>{{ item.server.APP_NAME }}</strong>
              <span
                v-if="!isServerEnabled(item.server)"
                class="status-pill status-off"
              >{{ $t('servers.disabled_badge') }}</span>
              <br />
              <a :href="'http://' + item.server.DOMAIN_NAME" target="_blank" rel="noreferrer">
                {{ item.server.DOMAIN_NAME }}
              </a>
            </td>
            <td><code>{{ item.server.CONTAINER_PHP_VERSION }}</code></td>
            <td><code>{{ item.server.SERVER_PATH }}</code></td>
            <td>
              <div class="actions">
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('toggle', { key: item.key }) }"
                  :disabled="busy"
                  :title="
                    isServerEnabled(item.server)
                      ? $t('action.disable_hint')
                      : $t('action.enable_hint')
                  "
                  @click="toggleServerEnabled(item.key)"
                >
                  <span
                    v-if="isPending('toggle', { key: item.key })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('toggle', { key: item.key })
                      ? $t('action.working')
                      : isServerEnabled(item.server)
                        ? $t('action.disable')
                        : $t('action.enable')
                  }}
                </button>
                <button type="button" :disabled="busy" @click="openTerminal(item)">
                  {{ $t('action.terminal') }}
                </button>
                <button type="button" :disabled="busy" @click="openEdit(item.key)">
                  {{ $t('action.edit') }}
                </button>
                <button
                  type="button"
                  class="danger"
                  :class="{ 'is-loading': isPending('delete', { key: item.key }) }"
                  :disabled="busy"
                  @click="deleteServer(item.key)"
                >
                  <span
                    v-if="isPending('delete', { key: item.key })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('delete', { key: item.key })
                      ? $t('action.working')
                      : $t('action.delete')
                  }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="panel-body command-block">
      <div class="command">
        <strong>{{ $t('apply.title') }}</strong>
        <pre v-if="!loading">{{ data.apply_command }}</pre>
        <div v-else class="skeleton-command">
          <span class="skeleton-line skeleton-w2"></span>
          <span class="skeleton-line skeleton-w1"></span>
        </div>
      </div>
    </div>
  </section>

  <div v-if="modalOpen" class="modal-backdrop" @click.self="!busy && closeModal()">
    <div
      class="modal-panel"
      role="dialog"
      aria-modal="true"
      :aria-label="editingKey ? $t('form.edit_title') : $t('form.add_title')"
    >
      <div class="modal-header">
        <h2>{{ editingKey ? $t('form.edit_title') : $t('form.add_title') }}</h2>
        <button type="button" class="modal-close" :disabled="busy" @click="closeModal">×</button>
      </div>
      <form class="modal-body" @submit.prevent="syncServerPathFromParts(); saveServer()">
        <fieldset :disabled="busy" class="modal-fieldset">
          <label>{{ $t('form.app_name') }}</label>
          <input v-model="form.app_name" :placeholder="$t('form.app_placeholder')" required />
          <div v-if="fieldErrors.app_name" class="error">{{ fieldErrors.app_name }}</div>

          <label>{{ $t('form.domain') }}</label>
          <input v-model="form.domain_name" :placeholder="$t('form.server_domain_placeholder')" required />
          <div v-if="fieldErrors.domain_name" class="error">{{ fieldErrors.domain_name }}</div>

          <label>{{ $t('form.php_version') }}</label>
          <select v-model="form.php_version">
            <option v-for="(cfg, id) in data.php_versions" :key="id" :value="id">
              {{ versionLabel(cfg) }}
            </option>
          </select>
          <div v-if="fieldErrors.php_version" class="error">{{ fieldErrors.php_version }}</div>

          <label>{{ t('form.framework') }}</label>
          <select
            v-model="frameworkId"
            data-tour="home-framework"
            @change="onFrameworkChange"
          >
            <option v-for="item in FRAMEWORK_PRESETS" :key="item.id" :value="item.id">
              {{ t(`form.framework_${item.id}`) }}
            </option>
          </select>
          <p class="form-path-hint">{{ frameworkHint }}</p>

          <label for="mgr-project-dir">{{ $t('form.project_dir') }}</label>
          <input
            id="mgr-project-dir"
            v-model="projectDir"
            :placeholder="$t('form.project_dir_placeholder')"
            required
            autocomplete="off"
            @input="onPathPartsInput"
          />

          <label for="mgr-doc-root">{{ $t('form.doc_root') }}</label>
          <input
            id="mgr-doc-root"
            v-model="docRoot"
            :placeholder="$t('form.doc_root_placeholder')"
            autocomplete="off"
            @input="onPathPartsInput"
          />
          <p class="form-path-hint form-path-hint-below">{{ $t('form.doc_root_hint') }}</p>
          <div v-if="fieldErrors.server_path" class="error">{{ fieldErrors.server_path }}</div>
          <div class="form-path-guide" data-tour="home-path-guide">
            <div>
              <span class="form-path-guide-label">{{ t('form.path_in_container') }}</span>
              <code>{{ pathExample }}</code>
            </div>
            <div>
              <span class="form-path-guide-label">{{ t('form.path_on_host') }}</span>
              <code>{{ hostExample }}</code>
            </div>
            <p class="create-hint">{{ t('form.path_guide_note') }}</p>
          </div>
        </fieldset>

        <div class="form-actions">
          <button
            type="submit"
            class="primary"
            :class="{ 'is-loading': isPending('save') }"
            :disabled="busy"
          >
            <span v-if="isPending('save')" class="btn-spinner" aria-hidden="true"></span>
            {{
              isPending('save')
                ? $t('action.working')
                : editingKey
                  ? $t('form.save')
                  : $t('form.add')
            }}
          </button>
          <button type="button" :disabled="busy" @click="closeModal">
            {{ $t('action.cancel') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
