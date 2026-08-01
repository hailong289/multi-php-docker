<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend, setCsrfToken } from './api'

const { t, locale } = useI18n()

const loading = ref(true)
const fatalError = ref('')
const notice = ref(null)
const editingKey = ref(null)
const fieldErrors = ref({})
const busy = ref(false)
const page = ref('home')
const modalOpen = ref(false)

const data = reactive({
  servers: {},
  php_versions: {},
  apply_command: '',
  nginx_status: null,
  php_controllers: { targets: {}, statuses: {} },
})

const form = reactive({
  app_name: '',
  domain_name: '',
  server_path: '/var/www/source_php8.2/',
  php_version: 'php-8.2',
})

const themeMode = ref(document.documentElement.dataset.themeMode || 'system')

const reloadMessageKeys = {
  'Nginx templates were generated and reloaded successfully.': 'reload.status.generated',
  'Could not generate Nginx templates. See runtime/nginx.reload.log.': 'reload.status.generate_failed',
  'Nginx configuration is invalid. Previous configuration was restored.': 'reload.status.invalid',
  'Nginx could not reload. Previous configuration was restored.': 'reload.status.failed',
}

const serverEntries = computed(() =>
  Object.entries(data.servers).map(([key, server]) => ({ key, server })),
)

function trKey(key, params = {}) {
  if (!key) return ''
  return t(key, params)
}

function translateApiError(error) {
  const payload = error?.payload?.error
  if (!payload?.key) return error?.message || t('error.load')
  return trKey(payload.key, payload.parameters || {})
}

function noticeFromResult(result) {
  if (!result?.message_key) return null
  const params = { ...(result.message_parameters || {}) }
  if (params.action) params.action = trKey(params.action)
  return { type: 'success', text: trKey(result.message_key, params) }
}

function versionLabel(cfg) {
  if (!cfg) return ''
  return cfg.default ? `${cfg.label} (${t('php.default')})` : cfg.label
}

function applyTheme(mode) {
  themeMode.value = mode
  try {
    localStorage.setItem('manager-theme', mode)
  } catch (_) {}
  const effective =
    mode === 'system'
      ? matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light'
      : mode
  document.documentElement.dataset.theme = effective
  document.documentElement.dataset.themeMode = mode
}

function onSystemThemeChange() {
  if (themeMode.value === 'system') applyTheme('system')
}

function setLocale(next) {
  locale.value = next
  try {
    localStorage.setItem('manager-locale', next)
  } catch (_) {}
  document.documentElement.lang = next
}

function applyBootstrap(payload) {
  data.servers = payload.servers || {}
  data.php_versions = payload.php_versions || {}
  data.apply_command = payload.apply_command || ''
  data.nginx_status = payload.nginx_status || null
  data.php_controllers = payload.php_controllers || { targets: {}, statuses: {} }
  if (payload.csrf_token) setCsrfToken(payload.csrf_token)
}

function versionFromContainer(container) {
  for (const [id, config] of Object.entries(data.php_versions)) {
    if (config.container === container) return id
  }
  return 'php-8.2'
}

function resetForm() {
  editingKey.value = null
  fieldErrors.value = {}
  form.app_name = ''
  form.domain_name = ''
  form.server_path = data.php_versions['php-8.2']?.source_prefix
    ? `${data.php_versions['php-8.2'].source_prefix}/`
    : '/var/www/source_php8.2/'
  form.php_version = 'php-8.2'
}

function openAddModal() {
  resetForm()
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
  resetForm()
}

function startEdit(key) {
  const server = data.servers[key]
  if (!server) return
  editingKey.value = key
  fieldErrors.value = {}
  form.app_name = server.APP_NAME || ''
  form.domain_name = server.DOMAIN_NAME || ''
  form.server_path = server.SERVER_PATH || ''
  form.php_version = versionFromContainer(server.CONTAINER_PHP_VERSION || '')
  modalOpen.value = true
}

watch(
  () => form.php_version,
  (version) => {
    const prefix = data.php_versions[version]?.source_prefix
    if (!prefix) return
    if (!form.server_path || form.server_path.startsWith('/var/www/source_php')) {
      if (!editingKey.value) {
        form.server_path = `${prefix}/`
      }
    }
  },
)

async function loadBootstrap() {
  loading.value = true
  fatalError.value = ''
  try {
    const payload = await apiGet('/api/bootstrap')
    applyBootstrap(payload)
  } catch (error) {
    fatalError.value = translateApiError(error)
  } finally {
    loading.value = false
  }
}

async function saveServer() {
  busy.value = true
  fieldErrors.value = {}
  notice.value = null
  try {
    const body = {
      app_name: form.app_name,
      domain_name: form.domain_name,
      server_path: form.server_path,
      php_version: form.php_version,
    }
    const result = editingKey.value
      ? await apiSend('PUT', `/api/servers/${editingKey.value}`, body)
      : await apiSend('POST', '/api/servers', body)
    if (result.bootstrap) applyBootstrap(result.bootstrap)
    notice.value = noticeFromResult(result)
    closeModal()
  } catch (error) {
    const fields = error.payload?.error?.fields || {}
    fieldErrors.value = Object.fromEntries(
      Object.entries(fields).map(([k, v]) => [k, trKey(v.key, v.parameters || {})]),
    )
    notice.value = {
      type: 'failure',
      text: translateApiError(error),
    }
  } finally {
    busy.value = false
  }
}

async function deleteServer(key) {
  if (!confirm(t('confirm.delete'))) return
  busy.value = true
  notice.value = null
  try {
    const result = await apiSend('DELETE', `/api/servers/${key}`)
    if (result.bootstrap) applyBootstrap(result.bootstrap)
    notice.value = noticeFromResult(result)
    if (editingKey.value === key) closeModal()
  } catch (error) {
    notice.value = {
      type: 'failure',
      text: translateApiError(error),
    }
  } finally {
    busy.value = false
  }
}

async function reloadNginx() {
  busy.value = true
  notice.value = null
  try {
    const result = await apiSend('POST', '/api/nginx/reload', {})
    notice.value = noticeFromResult(result)
    setTimeout(loadBootstrap, 1500)
  } catch (error) {
    notice.value = {
      type: 'failure',
      text: translateApiError(error),
    }
  } finally {
    busy.value = false
  }
}

async function phpAction(service, action) {
  busy.value = true
  notice.value = null
  try {
    const result = await apiSend('POST', `/api/php-controllers/${service}/${action}`, {})
    notice.value = noticeFromResult(result)
    if (result.php_controllers) data.php_controllers = result.php_controllers
    setTimeout(loadBootstrap, 1500)
  } catch (error) {
    notice.value = {
      type: 'failure',
      text: translateApiError(error),
    }
  } finally {
    busy.value = false
  }
}

function nginxStatusText() {
  const status = data.nginx_status
  if (!status) return ''
  const raw = status.message || ''
  const key = reloadMessageKeys[raw]
  return key ? t(key) : raw || t('reload.unknown')
}

function nginxStatusOk() {
  const status = data.nginx_status
  if (!status) return false
  if (typeof status.ok === 'boolean') return status.ok
  return status.status === 'success'
}

function stateClass(state) {
  if (state === 'running') return 'state-running'
  if (state === 'error' || state === 'busy') return `state-${state}`
  return ''
}

function stateLabel(state) {
  const map = {
    running: 'php_controller.state_running',
    stopped: 'php_controller.state_stopped',
    not_created: 'php_controller.state_not_created',
    busy: 'php_controller.state_busy',
    error: 'php_controller.state_error',
  }
  return t(map[state] || 'php_controller.state_not_created')
}

function phpServiceState(service) {
  return data.php_controllers.statuses[service]?.state || 'not_created'
}

function phpActionEnabled(service, action) {
  if (busy.value) return false
  const state = phpServiceState(service)
  if (state === 'busy' || state === 'error' || state === 'not_created') return false
  if (action === 'start') return state === 'stopped'
  if (action === 'stop' || action === 'restart') return state === 'running'
  return false
}

function showCreateHint(service, target) {
  return phpServiceState(service) === 'not_created' && target.profile !== null
}

onMounted(async () => {
  applyTheme(themeMode.value)
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onSystemThemeChange)
  document.title = t('page.title')
  await loadBootstrap()
})

onUnmounted(() => {
  matchMedia('(prefers-color-scheme: dark)').removeEventListener('change', onSystemThemeChange)
})

watch(locale, () => {
  document.title = t('page.title')
  loadBootstrap()
})
</script>

<template>
  <main class="shell">
    <header>
      <div>
        <h1>{{ t('header.title') }}</h1>
        <p>{{ t('header.subtitle') }}</p>
      </div>
      <div class="header-actions">
        <span class="badge">{{ t('header.local_only') }}</span>
        <div class="switcher">
          <span class="switcher-label">{{ t('language.label') }}</span>
          <div class="locale-form">
            <button type="button" :aria-current="locale === 'vi'" @click="setLocale('vi')">VI</button>
            <button type="button" :aria-current="locale === 'en'" @click="setLocale('en')">EN</button>
          </div>
        </div>
        <div class="switcher">
          <span class="switcher-label">{{ t('theme.label') }}</span>
          <select :value="themeMode" @change="applyTheme($event.target.value)">
            <option value="system">{{ t('theme.system') }}</option>
            <option value="light">{{ t('theme.light') }}</option>
            <option value="dark">{{ t('theme.dark') }}</option>
          </select>
        </div>
      </div>
    </header>

    <nav class="nav-menu" aria-label="Main">
      <button type="button" :aria-current="page === 'home'" @click="page = 'home'">
        {{ t('nav.home') }}
      </button>
      <button type="button" :aria-current="page === 'php'" @click="page = 'php'">
        {{ t('nav.php_versions') }}
      </button>
    </nav>

    <p v-if="loading" class="empty">{{ t('loading') }}</p>
    <div v-else-if="fatalError" class="notice failure">{{ fatalError }}</div>

    <template v-else>
      <div v-if="notice" class="notice" :class="notice.type">{{ notice.text }}</div>

      <section v-if="page === 'home'" class="panel">
        <div class="panel-heading">
          <div class="panel-heading-row">
            <h2>{{ t('servers.title') }}</h2>
            <div class="panel-heading-actions">
              <button type="button" class="primary" :disabled="busy" @click="openAddModal">
                {{ t('form.add') }}
              </button>
              <button type="button" :disabled="busy" @click="reloadNginx">
                {{ t('reload.button') }}
              </button>
            </div>
          </div>
          <p v-if="data.nginx_status" class="status-line">
            <strong>
              {{ nginxStatusOk() ? t('reload.success') : t('reload.error') }}:
            </strong>
            {{ nginxStatusText() }}
          </p>
        </div>

        <div v-if="serverEntries.length === 0" class="empty">{{ t('servers.empty') }}</div>
        <div v-else class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ t('table.app_domain') }}</th>
                <th>{{ t('table.php') }}</th>
                <th>{{ t('table.document_root') }}</th>
                <th>{{ t('table.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in serverEntries" :key="item.key">
                <td>
                  <strong>{{ item.server.APP_NAME }}</strong><br />
                  <a :href="'http://' + item.server.DOMAIN_NAME" target="_blank" rel="noreferrer">
                    {{ item.server.DOMAIN_NAME }}
                  </a>
                </td>
                <td><code>{{ item.server.CONTAINER_PHP_VERSION }}</code></td>
                <td><code>{{ item.server.SERVER_PATH }}</code></td>
                <td>
                  <div class="actions">
                    <button type="button" :disabled="busy" @click="startEdit(item.key)">
                      {{ t('action.edit') }}
                    </button>
                    <button
                      type="button"
                      class="danger"
                      :disabled="busy"
                      @click="deleteServer(item.key)"
                    >
                      {{ t('action.delete') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="panel-body command-block">
          <div class="command">
            <strong>{{ t('apply.title') }}</strong>
            <pre>{{ data.apply_command }}</pre>
          </div>
        </div>
      </section>

      <section v-else class="panel">
        <div class="panel-heading">
          <div class="controller-heading">
            <div>
              <h2>{{ t('php_controller.title') }}</h2>
              <p>{{ t('php_controller.subtitle') }}</p>
            </div>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ t('php_controller.version') }}</th>
                <th>{{ t('php_controller.container') }}</th>
                <th>{{ t('php_controller.profile') }}</th>
                <th>{{ t('php_controller.state') }}</th>
                <th>{{ t('php_controller.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(target, service) in data.php_controllers.targets" :key="service">
                <td>{{ target.label }}</td>
                <td><code>{{ target.container }}</code></td>
                <td><code>{{ target.profile || t('php_controller.default_profile') }}</code></td>
                <td>
                  <span class="state-badge" :class="stateClass(phpServiceState(service))">
                    {{ stateLabel(phpServiceState(service)) }}
                  </span>
                  <div v-if="showCreateHint(service, target)" class="create-hint">
                    {{ t('php_controller.create_hint') }}
                    <code>{{ target.create_command }}</code>
                  </div>
                </td>
                <td>
                  <div class="controller-actions">
                    <button
                      type="button"
                      :disabled="!phpActionEnabled(service, 'start')"
                      @click="phpAction(service, 'start')"
                    >
                      {{ t('php_controller.start') }}
                    </button>
                    <button
                      type="button"
                      :disabled="!phpActionEnabled(service, 'stop')"
                      @click="phpAction(service, 'stop')"
                    >
                      {{ t('php_controller.stop') }}
                    </button>
                    <button
                      type="button"
                      :disabled="!phpActionEnabled(service, 'restart')"
                      @click="phpAction(service, 'restart')"
                    >
                      {{ t('php_controller.restart') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div
        v-if="modalOpen"
        class="modal-backdrop"
        @click.self="closeModal"
      >
        <div class="modal-panel" role="dialog" aria-modal="true" :aria-label="editingKey ? t('form.edit_title') : t('form.add_title')">
          <div class="modal-header">
            <h2>{{ editingKey ? t('form.edit_title') : t('form.add_title') }}</h2>
            <button type="button" class="modal-close" :disabled="busy" @click="closeModal">×</button>
          </div>
          <form class="modal-body" @submit.prevent="saveServer">
            <label>{{ t('form.app_name') }}</label>
            <input v-model="form.app_name" :placeholder="t('form.app_placeholder')" required />
            <div v-if="fieldErrors.app_name" class="error">{{ fieldErrors.app_name }}</div>

            <label>{{ t('form.domain') }}</label>
            <input v-model="form.domain_name" :placeholder="t('form.domain_placeholder')" required />
            <div v-if="fieldErrors.domain_name" class="error">{{ fieldErrors.domain_name }}</div>

            <label>{{ t('form.php_version') }}</label>
            <select v-model="form.php_version">
              <option v-for="(cfg, id) in data.php_versions" :key="id" :value="id">
                {{ versionLabel(cfg) }}
              </option>
            </select>
            <div v-if="fieldErrors.php_version" class="error">{{ fieldErrors.php_version }}</div>

            <label>{{ t('form.server_path') }}</label>
            <input v-model="form.server_path" :placeholder="t('form.path_placeholder')" required />
            <div v-if="fieldErrors.server_path" class="error">{{ fieldErrors.server_path }}</div>

            <div class="form-actions">
              <button type="submit" class="primary" :disabled="busy">
                {{ editingKey ? t('form.save') : t('form.add') }}
              </button>
              <button type="button" :disabled="busy" @click="closeModal">
                {{ t('action.cancel') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </template>
  </main>
</template>
