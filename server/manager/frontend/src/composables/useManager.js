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
  hosts_status: null,
  hosts_extras: [],
  pending_sync: false,
  php_controllers: { targets: {}, statuses: {} },
  infra_services: { targets: {}, statuses: {} },
  supervisor_services: { targets: {}, statuses: {} },
})

const form = reactive({
  app_name: '',
  domain_name: '',
  server_path: '/var/www/source_php8.2/',
  php_version: 'php-8.2',
})

const domainForm = reactive({
  domain_name: '',
})
const domainModalOpen = ref(false)
const domainModalMode = ref('add')
const domainEditingKey = ref(null)
const domainFieldErrors = ref({})
const hostsManualOpen = ref(false)
const hostsManual = ref(null)
const hostsProgress = ref(null)

export function useManager() {
  const { t } = useI18n()

  const serverEntries = computed(() =>
    Object.entries(data.servers).map(([key, server]) => ({ key, server })),
  )

  const domainEntries = computed(() => {
    const states = data.hosts_status?.domains || {}
    const rows = []
    const seen = new Set()

    for (const [key, server] of Object.entries(data.servers)) {
      const domainName = (server.DOMAIN_NAME || '').toLowerCase()
      if (!domainName) continue
      seen.add(domainName)
      rows.push({
        key,
        source: 'server',
        app_name: server.APP_NAME || '',
        domain_name: domainName,
        hosts_state: data.hosts_status
          ? states[domainName] || 'missing'
          : 'unknown',
      })
    }

    for (const domainName of data.hosts_extras || []) {
      const normalized = String(domainName || '').toLowerCase()
      if (!normalized || seen.has(normalized)) continue
      rows.push({
        key: `hosts:${normalized}`,
        source: 'hosts',
        app_name: '',
        domain_name: normalized,
        hosts_state: data.hosts_status
          ? states[normalized] || 'missing'
          : 'unknown',
      })
    }

    return rows
  })

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
    if (meta.domain != null && current.domain !== meta.domain) return false
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
    data.hosts_status = payload.hosts_status || null
    data.hosts_extras = payload.hosts_extras || []
    data.pending_sync = !!payload.pending_sync
    data.php_controllers = payload.php_controllers || { targets: {}, statuses: {} }
    data.infra_services = payload.infra_services || { targets: {}, statuses: {} }
    data.supervisor_services = payload.supervisor_services || { targets: {}, statuses: {} }
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

  async function deleteServer(key, confirmKey = 'confirm.delete') {
    if (!confirm(t(confirmKey))) return
    busy.value = true
    pendingAction.value = { kind: 'delete', key }
    try {
      const result = await apiSend('DELETE', `/api/servers/${key}`)
      if (result.bootstrap) applyBootstrap(result.bootstrap)
      toastFromResult(result)
      if (editingKey.value === key) closeModal()
      if (domainEditingKey.value === key) closeDomainModal()
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  async function deleteDomain(key) {
    if (!String(key).startsWith('hosts:')) {
      showToast('failure', t('error.hosts_only_delete'))
      return
    }
    if (!confirm(t('domains.confirm_delete'))) return
    const domain = String(key).slice('hosts:'.length)
    busy.value = true
    pendingAction.value = { kind: 'delete', key }
    try {
      const result = await apiSend('DELETE', `/api/domains/extra/${encodeURIComponent(domain)}`)
      if (domainEditingKey.value === key) closeDomainModal()
      showToast('success', trKey(result.message_key || 'hosts.domain_removed'))
      await finishHostsWrite(result)
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

  async function infraAction(service, action) {
    busy.value = true
    pendingAction.value = { kind: 'infra', service, action }
    try {
      const result = await apiSend('POST', `/api/infra-services/${service}/${action}`, {})
      toastFromResult(result)
      if (result.infra_services) data.infra_services = result.infra_services
      setTimeout(loadBootstrap, 1500)
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  function openHostsDomainAdd() {
    domainModalMode.value = 'add'
    domainEditingKey.value = null
    domainFieldErrors.value = {}
    domainForm.domain_name = ''
    domainModalOpen.value = true
  }

  function openDomainEdit(key) {
    const row = domainEntries.value.find((item) => item.key === key)
    if (!row) return
    domainModalMode.value = 'edit'
    domainEditingKey.value = key
    domainFieldErrors.value = {}
    domainForm.domain_name = row.domain_name || ''
    domainModalOpen.value = true
  }

  function closeDomainModal() {
    domainModalOpen.value = false
    domainModalMode.value = 'add'
    domainEditingKey.value = null
    domainFieldErrors.value = {}
    domainForm.domain_name = ''
  }

  function closeHostsManual() {
    hostsManualOpen.value = false
  }

  function showHostsManual(manual) {
    if (!manual?.lines?.length) return
    hostsManual.value = manual
    hostsManualOpen.value = true
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms))
  }

  async function waitForHostsResult(previousUpdatedAt, maxAttempts = 5) {
    let latestBusy = null
    let pendingSync = false
    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
      hostsProgress.value = {
        attempt,
        maxAttempts,
        message_key: 'hosts.progress_checking',
      }
      await sleep(1000)
      try {
        const payload = await apiGet('/api/hosts/status')
        pendingSync = !!payload.pending_sync
        data.pending_sync = pendingSync
        const status = payload.hosts_status
        if (!status?.updated_at) {
          hostsProgress.value = {
            attempt,
            maxAttempts,
            message_key: pendingSync ? 'hosts.progress_waiting' : 'hosts.progress_waiting',
          }
          continue
        }
        if (previousUpdatedAt && status.updated_at === previousUpdatedAt) {
          hostsProgress.value = {
            attempt,
            maxAttempts,
            message_key: pendingSync ? 'hosts.progress_waiting' : 'hosts.progress_waiting',
          }
          continue
        }
        data.hosts_status = status
        if (status.status === 'busy') {
          latestBusy = status
          hostsProgress.value = {
            attempt,
            maxAttempts,
            message_key: status.message_key || 'hosts.processing',
          }
          continue
        }
        hostsProgress.value = {
          attempt,
          maxAttempts,
          message_key: status.message_key || 'hosts.progress_done',
        }
        return { status, pendingSync: false }
      } catch (_) {
        hostsProgress.value = {
          attempt,
          maxAttempts,
          message_key: 'hosts.progress_retry',
        }
      }
    }
    return { status: latestBusy, pendingSync }
  }

  function launchHostsWriteProtocol() {
    const ua = String(navigator.userAgent || '')
    const platform = String(navigator.platform || '')
    const isWin = /Win/i.test(ua) || /Win/i.test(platform)
    const isMac = /Mac/i.test(platform) || /Mac OS/i.test(ua) || /Macintosh/i.test(ua)
    // Windows + macOS: custom URL scheme registered by ensure_hosts_env.*
    if (!isWin && !isMac) return false
    try {
      const frame = document.createElement('iframe')
      frame.style.display = 'none'
      frame.src = 'multi-php-hosts:write'
      document.body.appendChild(frame)
      setTimeout(() => frame.remove(), 2500)
      return true
    } catch (_) {
      return false
    }
  }

  async function finishHostsWrite(result) {
    const previousUpdatedAt = data.hosts_status?.updated_at || null
    if (result?.bootstrap) applyBootstrap(result.bootstrap)
    else if (result?.hosts_status) data.hosts_status = result.hosts_status

    const launched = launchHostsWriteProtocol()
    hostsProgress.value = {
      attempt: 0,
      maxAttempts: 15,
      message_key: launched ? 'hosts.progress_protocol' : 'hosts.progress_starting',
    }

    try {
      const outcome = await waitForHostsResult(previousUpdatedAt, 15)
      const status = outcome?.status || null
      await loadBootstrap()
      data.pending_sync = !!outcome?.pendingSync || !!data.pending_sync

      if (status?.status === 'success') {
        data.pending_sync = false
        showToast('success', trKey(status.message_key || 'hosts.sync_success'))
        return
      }

      if (outcome?.pendingSync || data.pending_sync) {
        data.pending_sync = true
        showToast('failure', t(launched ? 'hosts.protocol_pending' : 'hosts.protocol_required'))
      }

      const manual = status?.manual || result?.manual || null
      if (manual?.lines?.length) {
        if (!(outcome?.pendingSync || data.pending_sync)) {
          showToast('failure', trKey(status?.message_key || 'hosts.manual_required'))
        }
        showHostsManual(manual)
        return
      }

      if (!(outcome?.pendingSync || data.pending_sync)) {
        showToast('failure', t('hosts.manual_required'))
      }
      if (result?.manual) showHostsManual(result.manual)
    } finally {
      hostsProgress.value = null
    }
  }

  async function saveDomain() {
    busy.value = true
    pendingAction.value = { kind: 'domain-save' }
    domainFieldErrors.value = {}
    const mode = domainModalMode.value
    const editingKeySnapshot = domainEditingKey.value
    try {
      let result
      if (mode === 'add') {
        result = await apiSend('POST', '/api/domains', {
          domain_name: domainForm.domain_name,
        })
      } else if (String(editingKeySnapshot).startsWith('hosts:')) {
        const current = String(editingKeySnapshot).slice('hosts:'.length)
        result = await apiSend('PUT', `/api/domains/extra/${encodeURIComponent(current)}`, {
          domain_name: domainForm.domain_name,
        })
      } else {
        result = await apiSend('PUT', `/api/domains/${editingKeySnapshot}`, {
          domain_name: domainForm.domain_name,
        })
      }
      closeDomainModal()
      // Write request + multi-php-hosts:write protocol (ensure_hosts_env.*).
      showToast('success', trKey(result.message_key || 'hosts.domain_added'))
      await finishHostsWrite(result)
    } catch (error) {
      const fields = error.payload?.error?.fields || {}
      domainFieldErrors.value = Object.fromEntries(
        Object.entries(fields).map(([k, v]) => [k, trKey(v.key, v.parameters || {})]),
      )
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  async function syncHosts() {
    busy.value = true
    pendingAction.value = { kind: 'hosts-sync' }
    try {
      // Refresh badges from the latest status written by the optional host helper.
      const result = await apiSend('POST', '/api/hosts/sync', {})
      data.pending_sync = !!result.pending_sync
      if (result.hosts_status) {
        data.hosts_status = result.hosts_status
        showToast('success', trKey(result.message_key || 'hosts.status_refreshed'))
      } else {
        showToast('failure', t('hosts.controller_unavailable'))
      }
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  async function writeDomainHostsAdmin(domainName) {
    const domain = String(domainName || '').toLowerCase()
    if (!domain) return
    busy.value = true
    pendingAction.value = { kind: 'hosts-admin', domain }
    try {
      const result = await apiSend('POST', '/api/hosts/sync', {
        force_admin: true,
        domain_name: domain,
      })
      data.pending_sync = !!result.pending_sync
      await finishHostsWrite({
        ...result,
        manual: result.manual || data.hosts_status?.manual || null,
      })
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  function hostsStateLabel(state) {
    const map = {
      synced: 'domains.state.synced',
      missing: 'domains.state.missing',
      stale: 'domains.state.stale',
      unknown: 'domains.state.unknown',
    }
    return t(map[state] || 'domains.state.unknown')
  }

  function hostsStateClass(state) {
    if (state === 'synced') return 'state-running'
    if (state === 'missing' || state === 'stale') return 'state-busy'
    return ''
  }

  function hostsStatusText() {
    const status = data.hosts_status
    if (!status) return t('hosts.controller_unavailable')
    if (status.message_key) return trKey(status.message_key)
    return t('hosts.controller_unavailable')
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
    const target = data.php_controllers.targets[service]
    if (action === 'create') {
      return state === 'not_created' && target?.profile != null
    }
    if (state === 'busy' || state === 'error' || state === 'not_created') return false
    if (action === 'start') return state === 'stopped'
    if (action === 'stop' || action === 'restart') return state === 'running'
    return false
  }

  function showCreateHint(service, target) {
    return phpServiceState(service) === 'not_created' && target.profile !== null
  }

  function infraServiceState(service) {
    return data.infra_services.statuses[service]?.state || 'not_created'
  }

  function infraActionEnabled(service, action) {
    if (busy.value) return false
    const state = infraServiceState(service)
    const target = data.infra_services.targets[service]
    if (action === 'create') {
      return state === 'not_created' && target?.profile != null
    }
    if (state === 'busy' || state === 'error' || state === 'not_created') return false
    if (action === 'start') return state === 'stopped'
    if (action === 'stop' || action === 'restart') return state === 'running'
    return false
  }

  function showInfraCreateHint(service, target) {
    return infraServiceState(service) === 'not_created' && target.profile !== null
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
    domainForm,
    domainModalOpen,
    domainModalMode,
    domainEditingKey,
    domainFieldErrors,
    hostsManualOpen,
    hostsManual,
    hostsProgress,
    serverEntries,
    domainEntries,
    versionLabel,
    loadBootstrap,
    openAddModal,
    openHostsDomainAdd,
    closeModal,
    startEdit,
    saveServer,
    deleteServer,
    deleteDomain,
    reloadNginx,
    phpAction,
    infraAction,
    openDomainEdit,
    closeDomainModal,
    saveDomain,
    syncHosts,
    writeDomainHostsAdmin,
    closeHostsManual,
    showHostsManual,
    hostsStateLabel,
    hostsStateClass,
    hostsStatusText,
    nginxStatusText,
    nginxStatusOk,
    stateClass,
    stateLabel,
    phpServiceState,
    phpActionEnabled,
    showCreateHint,
    infraServiceState,
    infraActionEnabled,
    showInfraCreateHint,
    isPending,
    showToast,
    dismissToast,
    translateApiError,
  }
}
