<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
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
import ActionMenu from '../components/ActionMenu.vue'
import { useManager } from '../composables/useManager'
import HomePinnedSection from '../components/HomePinnedSection.vue'
import DomainNameCombobox from '../components/DomainNameCombobox.vue'
import { apiGet, apiSend } from '../api'
import {
  FRAMEWORK_PRESETS,
  SOURCE_PREFIX,
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
  savePreset,
  showToast,
  deleteServer,
  regenerateSsl,
  toggleServerEnabled,
  isServerEnabled,
  reloadNginx,
  nginxStatusText,
  nginxStatusOk,
  isPending,
  phpServiceState,
  stateLabel,
  siteUrl,
} = useManager()

const frameworkId = ref('laravel')
const presetOn = ref(false)
const siteHealth = ref({})
let healthTimer = 0
const presetFrameworks = ['laravel', 'symfony', 'wordpress']
const pathTouched = ref(false)
const projectDir = ref('')
const docRoot = ref('public')

function openTerminal(item) {
  router.push({ name: 'terminal', params: { serverKey: item.key } })
}

function openSourceLogs(item) {
  router.push({ name: 'source-logs', params: { serverKey: item.key } })
}

function serverPhpState(server) {
  const container = server?.CONTAINER_PHP_VERSION || ''
  if (!container) return 'not_created'
  const match = Object.entries(data.php_versions || {}).find(
    ([, config]) => config?.container === container,
  )
  if (!match) return 'not_created'
  return phpServiceState(match[0])
}

function serverPhpRunning(server) {
  return serverPhpState(server) === 'running'
}

const sourcePrefix = computed(() => SOURCE_PREFIX)

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
  form.domain_name = String(form.domain_name || '').trim().toLowerCase()
  syncServerPathFromParts()
  if (!editingKey.value && presetOn.value && presetFrameworks.includes(frameworkId.value)) {
    savePreset(frameworkId.value)
    return
  }
  saveServer(editingKey.value ? {} : { framework: frameworkId.value })
}

// null = Docker unreachable or not checked yet: no DNS warning then.
const dnsState = ref(null)

async function loadHealth() {
  try {
    const payload = await apiGet('/api/health/sites')
    const next = {}
    for (const row of payload.sites || []) next[row.key] = row
    siteHealth.value = next
    dnsState.value = payload.dns_state ?? null
  } catch {
    siteHealth.value = {}
  }
}

async function trustSsl(key) {
  try {
    const result = await apiSend('POST', '/api/ssl/trust', { server_key: key })
    window.location.href = result.url
  } catch (error) {
    const keyName = error?.payload?.error?.key
    if (keyName === 'ssl.trust_requires_generated') {
      showToast('failure', t('ssl.trust_requires_generated'))
      return
    }
    showToast('failure', t('ssl.mkcert_missing'))
  }
}

function openHttps(domain) {
  window.open('https://' + domain)
}

/** ok | warn | bad, or '' before the first probe. Drives the row rail and the HTTP number. */
function healthTone(key) {
  const row = siteHealth.value[key]
  if (!row) return ''
  const status = Number(row.status)
  if (row.state === 'up' && status < 300) return 'ok'
  if ((row.state === 'up' || row.state === 'down') && status < 500) return 'warn'
  return 'bad'
}

const statusFilter = ref('all')
const siteQuery = ref('')

/** ok | warn | bad | off (disabled) | unknown (not probed yet). */
function rowTone(item) {
  if (!isServerEnabled(item.server)) return 'off'
  return healthTone(item.key) || 'unknown'
}

const filterOptions = computed(() => {
  const counts = { all: serverEntries.value.length, bad: 0, warn: 0, ok: 0 }
  for (const item of serverEntries.value) {
    const tone = rowTone(item)
    if (tone in counts) counts[tone] += 1
  }
  return ['all', 'bad', 'warn', 'ok'].map((id) => ({
    id,
    label: t(`servers.filter_${id}`),
    count: counts[id],
  }))
})

const visibleEntries = computed(() => {
  const query = siteQuery.value.trim().toLowerCase()
  return serverEntries.value.filter((item) => {
    if (statusFilter.value !== 'all' && rowTone(item) !== statusFilter.value) return false
    if (!query) return true
    const name = String(item.server.APP_NAME || '').toLowerCase()
    const domain = String(item.server.DOMAIN_NAME || '').toLowerCase()
    return name.includes(query) || domain.includes(query)
  })
})

function tileCode(item) {
  if (!isServerEnabled(item.server)) return t('servers.tile_off')
  const row = siteHealth.value[item.key]
  if (!row) return '…'
  if (row.state === 'up' || row.state === 'down') return String(row.status ?? '—')
  return '—'
}

function tileSub(item) {
  if (!isServerEnabled(item.server)) return 'Nginx'
  const row = siteHealth.value[item.key]
  if (!row) return ''
  if (row.state === 'up') return `${row.ms} ms`
  if (row.state === 'down') return t(`servers.filter_${rowTone(item)}`)
  return t('servers.tile_down')
}

function tileTitle(item) {
  if (!isServerEnabled(item.server)) return t('action.enable_hint')
  const row = siteHealth.value[item.key]
  if (!row) return ''
  return row.state === 'up' || row.state === 'down' ? `HTTP ${row.status}` : t('health.unreachable')
}

function serverPhpLabel(server) {
  const container = server?.CONTAINER_PHP_VERSION || ''
  const match = Object.values(data.php_versions || {}).find((config) => config?.container === container)
  return match?.label || container
}

function docRootParts(path) {
  const value = String(path || '')
  const prefix = `${SOURCE_PREFIX}/`
  return value.startsWith(prefix) ? { prefix, rest: value.slice(prefix.length) } : { prefix: '', rest: value }
}

function serverMenuItems(item) {
  const enabled = isServerEnabled(item.server)
  return [
    {
      label: enabled ? t('action.disable') : t('action.enable'),
      loading: isPending('toggle', { key: item.key }),
      disabled: busy.value,
      run: () => toggleServerEnabled(item.key),
    },
    {
      label: t('action.delete'),
      danger: true,
      loading: isPending('delete', { key: item.key }),
      disabled: busy.value,
      run: () => deleteServer(item.key),
    },
  ]
}

onMounted(() => {
  loadHealth()
  healthTimer = window.setInterval(loadHealth, 15000)
})
onUnmounted(() => window.clearInterval(healthTimer))
</script>

<template>
  <HomePinnedSection />
  <section class="panel" data-tour="home-panel">
    <div class="panel-heading">
      <div class="panel-heading-row">
        <div class="home-servers-title">
          <h2>{{ t('servers.title') }}</h2>
          <p v-if="!loading && isPending('reload')" class="status-line">
            {{ t('reload.waiting') }}
          </p>
          <p v-else-if="!loading && data.nginx_status && nginxStatusOk()" class="home-nginx-ok">
            <i class="pi pi-check" aria-hidden="true" />
            {{ nginxStatusText() }}
          </p>
        </div>
        <div class="panel-heading-actions">
          <Button
            v-if="nginxReloadAvailable"
            type="button"
            data-tour="home-reload"
            severity="secondary"
            outlined
            :label="isPending('reload') ? t('reload.waiting') : t('reload.button')"
            :loading="isPending('reload')"
            :disabled="busy || loading"
            @click="reloadNginx"
          />
          <Button
            type="button"
            data-tour="home-add"
            icon="pi pi-plus"
            :label="t('form.add')"
            :disabled="busy || loading"
            @click="openAdd"
          />
        </div>
      </div>
      <Message
        v-if="!loading && !isPending('reload') && data.nginx_status && !nginxStatusOk()"
        severity="error"
        :closable="false"
        class="home-status-msg"
      >
        <strong>{{ t('reload.error') }}:</strong>
        {{ nginxStatusText() }}
      </Message>
      <Message
        v-if="dnsState && dnsState !== 'running'"
        severity="error"
        :closable="false"
        class="home-status-msg"
      >
        {{ t('dns.home_down') }}
        <a href="#" @click.prevent="router.push({ name: 'domains' })">{{ t('dns.home_open') }}</a>
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
    </DataTable>
    <div v-else-if="serverEntries.length === 0" class="empty" data-tour="home-table">
      {{ t('servers.empty') }}
    </div>
    <div v-else data-tour="home-table">
      <div class="home-site-tools">
        <div class="home-site-filters" role="group" :aria-label="t('servers.filter_label')">
          <button
            v-for="option in filterOptions"
            :key="option.id"
            type="button"
            class="home-site-filter"
            :class="`is-${option.id}`"
            :aria-pressed="statusFilter === option.id ? 'true' : 'false'"
            @click="statusFilter = option.id"
          >
            <span class="home-site-filter-dot" aria-hidden="true" />
            {{ option.label }}
            <span class="home-site-filter-count">{{ option.count }}</span>
          </button>
        </div>
        <span class="home-site-search">
          <i class="pi pi-search" aria-hidden="true" />
          <InputText
            v-model="siteQuery"
            type="search"
            size="small"
            :placeholder="t('servers.search')"
            :aria-label="t('servers.search')"
            autocomplete="off"
          />
        </span>
      </div>

      <ul class="home-site-list">
        <li
          v-for="item in visibleEntries"
          :key="item.key"
          class="home-site"
          :class="{ 'is-disabled': !isServerEnabled(item.server) }"
        >
          <div class="home-site-tile" :class="`is-${rowTone(item)}`" :title="tileTitle(item)">
            <strong>{{ tileCode(item) }}</strong>
            <small>{{ tileSub(item) }}</small>
          </div>

          <div class="home-site-main">
            <div class="home-site-name">
              <strong>{{ item.server.APP_NAME }}</strong>
              <Tag
                v-if="!isServerEnabled(item.server)"
                :value="t('servers.disabled_badge')"
                severity="secondary"
                rounded
              />
            </div>
            <a
              v-if="item.server.DOMAIN_NAME"
              :href="siteUrl(item.server.DOMAIN_NAME)"
              target="_blank"
              rel="noreferrer"
            >{{ siteUrl(item.server.DOMAIN_NAME) }}</a>
            <span v-else class="home-site-muted">{{ t('servers.no_domain') }}</span>
            <div v-if="item.server.DOMAIN_NAME && isSslEnabled(item.server)" class="home-site-ssl">
              <a :href="siteUrl(item.server.DOMAIN_NAME, true)" target="_blank" rel="noreferrer">
                {{ siteUrl(item.server.DOMAIN_NAME, true) }}
              </a>
              <Button
                type="button"
                link
                size="small"
                :label="t('ssl.open')"
                @click="openHttps(item.server.DOMAIN_NAME)"
              />
              <Button
                v-if="item.server.ssl_mode !== 'uploaded'"
                type="button"
                link
                size="small"
                :label="t('ssl.trust')"
                :disabled="busy"
                @click="trustSsl(item.key)"
              />
              <Tag
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
            </div>
          </div>

          <div class="home-php-cell" :title="item.server.CONTAINER_PHP_VERSION">
            <span class="home-php-name">{{ serverPhpLabel(item.server) }}</span>
            <span class="home-php-state" :class="`is-${serverPhpState(item.server)}`">
              {{ stateLabel(serverPhpState(item.server)) }}
            </span>
          </div>

          <div class="home-site-path">
            <i class="pi pi-folder" aria-hidden="true" />
            <code class="home-doc-root" :title="item.server.SERVER_PATH"><span
                v-if="docRootParts(item.server.SERVER_PATH).prefix"
                class="home-doc-root-prefix"
              >{{ docRootParts(item.server.SERVER_PATH).prefix }}</span>{{ docRootParts(item.server.SERVER_PATH).rest }}</code>
          </div>

          <div class="home-row-toolbar">
            <Button
              type="button"
              text
              size="small"
              icon="pi pi-desktop"
              :label="t('action.terminal')"
              :title="serverPhpRunning(item.server) ? '' : t('terminal.container_not_running')"
              :disabled="busy || !serverPhpRunning(item.server)"
              @click="openTerminal(item)"
            />
            <Button
              type="button"
              text
              size="small"
              icon="pi pi-file"
              :label="t('action.logs')"
              :disabled="busy"
              @click="openSourceLogs(item)"
            />
            <Button
              type="button"
              text
              size="small"
              icon="pi pi-pencil"
              :label="t('action.edit')"
              :disabled="busy"
              @click="openEdit(item.key)"
            />
            <ActionMenu compact :label="t('action.more')" :items="serverMenuItems(item)" />
          </div>
        </li>
      </ul>
      <p v-if="!visibleEntries.length" class="home-site-empty">{{ t('servers.no_match') }}</p>
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
        <DomainNameCombobox v-model="form.domain_name" />
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
        <label v-if="!editingKey && presetFrameworks.includes(frameworkId)" class="form-path-hint">
          <Checkbox v-model="presetOn" binary />
          {{ frameworkId === 'wordpress' ? t('preset.wordpress') : t('preset.enable') }}
        </label>

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
