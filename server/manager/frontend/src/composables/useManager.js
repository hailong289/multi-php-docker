import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend, setCsrfToken } from '../api'

const reloadMessageKeys = {
  'Nginx templates were generated and reloaded successfully.': 'reload.status.generated',
  'Could not generate Nginx templates. See runtime/nginx.reload.log.': 'reload.status.generate_failed',
  'Nginx configuration is invalid. Previous configuration was restored.': 'reload.status.invalid',
  'Nginx could not reload. Previous configuration was restored.': 'reload.status.failed',
}

const loading = ref(true)
const fatalError = ref('')
const toasts = ref([])
const editingKey = ref(null)
const fieldErrors = ref({})
const busy = ref(false)
const pendingAction = ref(null)
const modalOpen = ref(false)
const bootstrapped = ref(false)
let toastSeq = 0
const toastTimers = new Map()

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

export function useManager() {
  const { t } = useI18n()

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

  function dismissToast(id) {
    const timer = toastTimers.get(id)
    if (timer) {
      clearTimeout(timer)
      toastTimers.delete(id)
    }
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  function showToast(type, text) {
    if (!text) return
    const id = ++toastSeq
    toasts.value = [...toasts.value, { id, type, text }]
    const timer = setTimeout(() => dismissToast(id), 4200)
    toastTimers.set(id, timer)
  }

  function toastFromResult(result) {
    if (!result?.message_key) return
    const params = { ...(result.message_parameters || {}) }
    if (params.action) params.action = trKey(params.action)
    showToast('success', trKey(result.message_key, params))
  }

  function isPending(kind, meta = {}) {
    const current = pendingAction.value
    if (!current || current.kind !== kind) return false
    if (meta.key != null && current.key !== meta.key) return false
    if (meta.service != null && current.service !== meta.service) return false
    if (meta.action != null && current.action !== meta.action) return false
    return true
  }

  function versionLabel(cfg) {
    if (!cfg) return ''
    return cfg.default ? `${cfg.label} (${t('php.default')})` : cfg.label
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

  async function loadBootstrap() {
    loading.value = true
    fatalError.value = ''
    try {
      const payload = await apiGet('/api/bootstrap')
      applyBootstrap(payload)
      bootstrapped.value = true
    } catch (error) {
      fatalError.value = translateApiError(error)
    } finally {
      loading.value = false
    }
  }

  async function saveServer() {
    busy.value = true
    pendingAction.value = { kind: 'save' }
    fieldErrors.value = {}
    try {
      const body = { ...form }
      const result = editingKey.value
        ? await apiSend('PUT', `/api/servers/${editingKey.value}`, body)
        : await apiSend('POST', '/api/servers', body)
      if (result.bootstrap) applyBootstrap(result.bootstrap)
      toastFromResult(result)
      closeModal()
    } catch (error) {
      const fields = error.payload?.error?.fields || {}
      fieldErrors.value = Object.fromEntries(
        Object.entries(fields).map(([k, v]) => [k, trKey(v.key, v.parameters || {})]),
      )
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  async function deleteServer(key) {
    if (!confirm(t('confirm.delete'))) return
    busy.value = true
    pendingAction.value = { kind: 'delete', key }
    try {
      const result = await apiSend('DELETE', `/api/servers/${key}`)
      if (result.bootstrap) applyBootstrap(result.bootstrap)
      toastFromResult(result)
      if (editingKey.value === key) closeModal()
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  async function reloadNginx() {
    busy.value = true
    pendingAction.value = { kind: 'reload' }
    try {
      const result = await apiSend('POST', '/api/nginx/reload', {})
      toastFromResult(result)
      setTimeout(loadBootstrap, 1500)
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  async function phpAction(service, action) {
    busy.value = true
    pendingAction.value = { kind: 'php', service, action }
    try {
      const result = await apiSend('POST', `/api/php-controllers/${service}/${action}`, {})
      toastFromResult(result)
      if (result.php_controllers) data.php_controllers = result.php_controllers
      setTimeout(loadBootstrap, 1500)
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
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

  watch(
    () => form.php_version,
    (version) => {
      const prefix = data.php_versions[version]?.source_prefix
      if (!prefix || editingKey.value) return
      if (!form.server_path || form.server_path.startsWith('/var/www/source_php')) {
        form.server_path = `${prefix}/`
      }
    },
  )

  return {
    loading,
    fatalError,
    toasts,
    editingKey,
    fieldErrors,
    busy,
    pendingAction,
    modalOpen,
    bootstrapped,
    data,
    form,
    serverEntries,
    versionLabel,
    loadBootstrap,
    openAddModal,
    closeModal,
    startEdit,
    saveServer,
    deleteServer,
    reloadNginx,
    phpAction,
    nginxStatusText,
    nginxStatusOk,
    stateClass,
    stateLabel,
    phpServiceState,
    phpActionEnabled,
    showCreateHint,
    isPending,
    showToast,
    dismissToast,
    translateApiError,
  }
}
