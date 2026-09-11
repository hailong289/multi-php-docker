<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import { useManager } from '../composables/useManager'
import HomePinnedSection from '../components/HomePinnedSection.vue'
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
  regenerateSsl,
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

const nginxReloadAvailable = computed(() => data.nginx_management?.state === 'running')

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

const phpOptions = computed(() =>
  Object.entries(data.php_versions || {}).map(([id, cfg]) => ({
    label: versionLabel(cfg),
    value: id,
  })),
)

const frameworkOptions = computed(() =>
  FRAMEWORK_PRESETS.map((item) => ({
    label: t(`form.framework_${item.id}`),
    value: item.id,
  })),
)

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

function isSslEnabled(server) {
  return server?.ssl_enabled === true || server?.SSL_ENABLED === true
}

async function onSslFile(kind, event) {
  const file = event.target.files?.[0]
  const text = file ? await file.text() : ''
  if (kind === 'cert') form.ssl_certificate = text
  else form.ssl_private_key = text
}

function onSubmit() {
  syncServerPathFromParts()
  saveServer()
}
</script>

<template>
  <HomePinnedSection />
  <section class="panel" data-tour="home-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <h2>{{ t('servers.title') }}</h2>
        <div class="panel-heading-actions">
          <Button
            type="button"
            data-tour="home-add"
            :label="t('form.add')"
            :disabled="busy || loading"
            @click="openAdd"
          />
          <Button
            v-if="nginxReloadAvailable"
            type="button"
            data-tour="home-reload"
            :label="isPending('reload') ? t('reload.waiting') : t('reload.button')"
            :loading="isPending('reload')"
            :disabled="busy || loading"
            @click="reloadNginx"
          />
        </div>
      </div>
      <p v-if="!loading && isPending('reload')" class="status-line">
        {{ t('reload.waiting') }}
      </p>
      <Message
        v-else-if="!loading && data.nginx_status"
        :severity="nginxStatusOk() ? 'success' : 'error'"
        :closable="false"
        class="home-status-msg"
      >
        <strong>
          {{ nginxStatusOk() ? t('reload.success') : t('reload.error') }}:
        </strong>
        {{ nginxStatusText() }}
      </Message>
    </div>

    <DataTable
      v-if="loading"
      data-tour="home-table"
      :value="[{}, {}, {}, {}]"
      :loading="true"
    >
      <Column :header="t('table.app_domain')" />
      <Column :header="t('table.php')" />
      <Column :header="t('table.document_root')" />
      <Column :header="t('table.actions')" />
    </DataTable>
    <div v-else-if="serverEntries.length === 0" class="empty" data-tour="home-table">
      {{ t('servers.empty') }}
    </div>
    <div v-else data-tour="home-table">
      <DataTable
        :value="serverEntries"
        data-key="key"
        striped-rows
        :row-class="(row) => (!isServerEnabled(row.server) ? 'is-disabled' : '')"
      >
        <Column :header="t('table.app_domain')">
          <template #body="{ data: item }">
            <strong>{{ item.server.APP_NAME }}</strong>
            <Tag
              v-if="!isServerEnabled(item.server)"
              class="home-inline-tag"
              :value="t('servers.disabled_badge')"
              severity="secondary"
              rounded
            />
            <br />
            <a :href="'http://' + item.server.DOMAIN_NAME" target="_blank" rel="noreferrer">
              http://{{ item.server.DOMAIN_NAME }}
            </a>
            <template v-if="isSslEnabled(item.server)">
              <br />
              <a :href="'https://' + item.server.DOMAIN_NAME" target="_blank" rel="noreferrer">
                https://{{ item.server.DOMAIN_NAME }}
              </a>
              <Tag
                class="home-inline-tag"
                :value="
                  item.server.ssl_mode === 'uploaded'
                    ? t('ssl.badge_uploaded')
                    : t('ssl.badge_generated')
                "
                :severity="item.server.ssl_mode === 'uploaded' ? 'success' : 'info'"
                rounded
              />
              <p v-if="item.server.ssl_files_present === false" class="ssl-warn">
                {{ t('ssl.files_missing') }}
                <Button
                  v-if="item.server.ssl_mode !== 'uploaded'"
                  type="button"
                  link
                  size="small"
                  :label="t('action.ssl_regenerate')"
                  :loading="isPending('ssl-regenerate', { key: item.key })"
                  :disabled="busy"
                  @click="regenerateSsl(item.key)"
                />
              </p>
              <p v-else-if="item.server.ssl_names_match === false" class="ssl-warn">
                {{ t('ssl.names_mismatch') }}
              </p>
            </template>
          </template>
        </Column>
        <Column :header="t('table.php')">
          <template #body="{ data: item }">
            <code>{{ item.server.CONTAINER_PHP_VERSION }}</code>
          </template>
        </Column>
        <Column :header="t('table.document_root')">
          <template #body="{ data: item }">
            <code>{{ item.server.SERVER_PATH }}</code>
          </template>
        </Column>
        <Column :header="t('table.actions')">
          <template #body="{ data: item }">
            <div class="actions">
              <Button
                type="button"
                size="small"
                :label="
                  isPending('toggle', { key: item.key })
                    ? t('action.working')
                    : isServerEnabled(item.server)
                      ? t('action.disable')
                      : t('action.enable')
                "
                :loading="isPending('toggle', { key: item.key })"
                :disabled="busy"
                :title="
                  isServerEnabled(item.server)
                    ? t('action.disable_hint')
                    : t('action.enable_hint')
                "
                @click="toggleServerEnabled(item.key)"
              />
              <Button
                type="button"
                size="small"
                :label="t('action.terminal')"
                :disabled="busy"
                @click="openTerminal(item)"
              />
              <Button
                type="button"
                size="small"
                :label="t('action.edit')"
                :disabled="busy"
                @click="openEdit(item.key)"
              />
              <Button
                type="button"
                size="small"
                severity="danger"
                outlined
                :label="
                  isPending('delete', { key: item.key })
                    ? t('action.working')
                    : t('action.delete')
                "
                :loading="isPending('delete', { key: item.key })"
                :disabled="busy"
                @click="deleteServer(item.key)"
              />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>

    <div class="panel-body command-block">
      <div class="command">
        <strong>{{ t('apply.title') }}</strong>
        <pre v-if="!loading">{{ data.apply_command }}</pre>
        <div v-else class="skeleton-command">
          <span class="skeleton-line skeleton-w2"></span>
          <span class="skeleton-line skeleton-w1"></span>
        </div>
      </div>
    </div>
  </section>

  <Dialog
    :visible="modalOpen"
    modal
    :header="editingKey ? t('form.edit_title') : t('form.add_title')"
    :closable="!busy"
    :dismissable-mask="!busy"
    :style="{ width: 'min(640px, 100%)' }"
    @update:visible="(v) => { if (!v) closeModal() }"
  >
    <form class="home-modal-form" @submit.prevent="onSubmit">
      <fieldset :disabled="busy" class="modal-fieldset">
        <label>{{ t('form.app_name') }}</label>
        <InputText
          v-model="form.app_name"
          :placeholder="t('form.app_placeholder')"
          required
          fluid
        />
        <small v-if="fieldErrors.app_name" class="p-error">{{ fieldErrors.app_name }}</small>

        <label>{{ t('form.domain') }}</label>
        <InputText
          v-model="form.domain_name"
          :placeholder="t('form.server_domain_placeholder')"
          required
          fluid
        />
        <small v-if="fieldErrors.domain_name" class="p-error">{{ fieldErrors.domain_name }}</small>

        <label>{{ t('form.php_version') }}</label>
        <Select
          v-model="form.php_version"
          :options="phpOptions"
          option-label="label"
          option-value="value"
          fluid
        />
        <small v-if="fieldErrors.php_version" class="p-error">{{ fieldErrors.php_version }}</small>

        <label>{{ t('form.framework') }}</label>
        <Select
          v-model="frameworkId"
          data-tour="home-framework"
          :options="frameworkOptions"
          option-label="label"
          option-value="value"
          fluid
          @update:model-value="onFrameworkChange"
        />
        <p class="form-path-hint">{{ frameworkHint }}</p>

        <label for="mgr-project-dir">{{ t('form.project_dir') }}</label>
        <InputText
          id="mgr-project-dir"
          v-model="projectDir"
          :placeholder="t('form.project_dir_placeholder')"
          required
          autocomplete="off"
          fluid
          @update:model-value="onPathPartsInput"
        />

        <label for="mgr-doc-root">{{ t('form.doc_root') }}</label>
        <InputText
          id="mgr-doc-root"
          v-model="docRoot"
          :placeholder="t('form.doc_root_placeholder')"
          autocomplete="off"
          fluid
          @update:model-value="onPathPartsInput"
        />
        <p class="form-path-hint form-path-hint-below">{{ t('form.doc_root_hint') }}</p>
        <small v-if="fieldErrors.server_path" class="p-error">{{ fieldErrors.server_path }}</small>
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

        <div class="follow-toggle form-ssl-toggle">
          <Checkbox v-model="form.ssl_enabled" binary input-id="form-ssl-enabled" />
          <label for="form-ssl-enabled">{{ t('form.ssl_enable') }}</label>
        </div>
        <p class="form-path-hint">{{ t('form.ssl_hint') }}</p>
        <template v-if="form.ssl_enabled">
          <label>{{ t('form.ssl_cert') }}</label>
          <input
            type="file"
            accept=".crt,.pem,.cer,application/x-pem-file,application/x-x509-ca-cert"
            @change="onSslFile('cert', $event)"
          />
          <small v-if="fieldErrors.ssl_certificate" class="p-error">{{ fieldErrors.ssl_certificate }}</small>
          <label>{{ t('form.ssl_key') }}</label>
          <input
            type="file"
            accept=".key,.pem,application/x-pem-file"
            @change="onSslFile('key', $event)"
          />
          <small v-if="fieldErrors.ssl_private_key" class="p-error">{{ fieldErrors.ssl_private_key }}</small>
        </template>
      </fieldset>

      <div class="form-actions">
        <Button
          type="submit"
          :label="
            isPending('save')
              ? t('action.working')
              : editingKey
                ? t('form.save')
                : t('form.add')
          "
          :loading="isPending('save')"
          :disabled="busy"
        />
        <Button
          type="button"
          severity="secondary"
          outlined
          :label="t('action.cancel')"
          :disabled="busy"
          @click="closeModal"
        />
      </div>
    </form>
  </Dialog>
</template>
