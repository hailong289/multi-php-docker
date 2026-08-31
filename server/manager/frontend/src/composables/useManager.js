import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend, setCsrfToken } from '../api'
import { applySessionPayload, authState } from '../lib/authState'
import { launchHostsWriteProtocol, newHostsWriteToken } from '../lib/hostsProtocol'
import { composeLocalDomain, parseLocalDomain } from '../lib/localDomain'

const reloadMessageKeys = {
  'Nginx templates were generated and reloaded successfully.': 'reload.status.generated',
  'Nginx templates were synced and reloaded successfully.': 'reload.status.synced',
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
  nginx_management: null,
  hosts_status: null,
  hosts_extras: [],
  hosts_write_enabled: true,
  pending_sync: false,
  php_controllers: { targets: {}, statuses: {} },
  infra_services: { targets: {}, statuses: {}, compose_files: [] },
  supervisor_services: { targets: {}, statuses: {} },
  php_controller_daemon: {
    container: 'php_controller_container',
    state: 'running',
    start_available: false,
  },
})

const form = reactive({
  app_name: '',
  domain_name: '',
  server_path: '/var/www/source_php8.5/',
  php_version: 'php-8.5',
  enabled: true,
  ssl_enabled: false,
  ssl_certificate: '',
  ssl_private_key: '',
})

const domainForm = reactive({
  domain_label: '',
  domain_tld: '.test',
  domain_custom: '',
})
const domainModalOpen = ref(false)
const domainModalMode = ref('add')
const domainEditingKey = ref(null)
const domainFieldErrors = ref({})
const hostsManualOpen = ref(false)
const hostsManual = ref(null)
const hostsProgress = ref(null)
const pullProgress = ref(null)
let pullProgressTimer = null

const PULL_TRACKED_ACTIONS = new Set(['create', 'pull-recreate', 'recreate'])
const PULL_PROGRESS_POLL_MS = 2000

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
    if (meta.name != null && current.name !== meta.name) return false
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
    data.nginx_management = payload.nginx_management || null
    data.hosts_status = payload.hosts_status || null
    data.hosts_extras = payload.hosts_extras || []
    data.hosts_write_enabled = payload.hosts_write_enabled !== false
    data.pending_sync = !!payload.pending_sync
    data.php_controllers = payload.php_controllers || { targets: {}, statuses: {} }
    data.infra_services = payload.infra_services || { targets: {}, statuses: {}, compose_files: [] }
    data.supervisor_services = payload.supervisor_services || { targets: {}, statuses: {} }
    data.php_controller_daemon = payload.php_controller_daemon || {
      container: 'php_controller_container',
      state: 'running',
      start_available: false,
    }
    if (payload.csrf_token) setCsrfToken(payload.csrf_token)
  }

  function versionFromContainer(container) {
    for (const [id, config] of Object.entries(data.php_versions)) {
      if (config.container === container) return id
    }
    return 'php-8.5'
  }

  function isServerEnabled(server) {
    return server?.ENABLED !== false && server?.ENABLED !== 0 && server?.ENABLED !== 'false' && server?.ENABLED !== '0'
  }

  function resetForm() {
    editingKey.value = null
    fieldErrors.value = {}
    form.app_name = ''
    form.domain_name = ''
    form.server_path = data.php_versions['php-8.5']?.source_prefix
      ? `${data.php_versions['php-8.5'].source_prefix}/`
      : '/var/www/source_php8.5/'
    form.php_version = 'php-8.5'
    form.enabled = true
    form.ssl_enabled = false
    form.ssl_certificate = ''
    form.ssl_private_key = ''
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
    form.enabled = isServerEnabled(server)
    form.ssl_enabled = server.ssl_enabled === true || server.SSL_ENABLED === true
    form.ssl_certificate = ''
    form.ssl_private_key = ''
    modalOpen.value = true
  }

  async function loadBootstrap({ silent = false } = {}) {
    if (authState.remote && (!authState.authenticated || authState.locked)) {
      bootstrapped.value = false
      if (!silent) loading.value = false
      return
    }
    if (!silent) {
      loading.value = true
      fatalError.value = ''
    }
    try {
      const payload = await apiGet('/api/bootstrap')
      applyBootstrap(payload)
      bootstrapped.value = true
    } catch (error) {
      if (error?.status === 401 && authState.remote) {
        applySessionPayload({
          remote: true,
          authenticated: false,
          locked: authState.locked,
          domain: authState.domain,
        })
        bootstrapped.value = false
        const { default: router } = await import('../router')
        await router.push({ name: 'login' })
        return
      }
      if (!silent) {
        fatalError.value = translateApiError(error)
      }
    } finally {
      if (!silent) {
        loading.value = false
      }
    }
  }

  async function logout() {
    try {
      const result = await apiSend('POST', '/api/logout', {})
      if (result.csrf_token) setCsrfToken(result.csrf_token)
    } catch (_) {
      /* still clear local auth */
    }
    applySessionPayload({
      remote: authState.remote,
      authenticated: false,
      locked: authState.locked,
      domain: authState.domain,
      hosts_write_enabled: authState.hosts_write_enabled,
    })
    bootstrapped.value = false
    const { default: router } = await import('../router')
    await router.push({ name: 'login' })
  }

  async function saveServer() {
    busy.value = true
    pendingAction.value = { kind: 'save' }
    fieldErrors.value = {}
    try {
      const body = {
        app_name: form.app_name,
        domain_name: form.domain_name,
        server_path: form.server_path,
        php_version: form.php_version,
        enabled: form.enabled,
        ssl_enabled: !!form.ssl_enabled,
      }
      if (form.ssl_certificate) body.ssl_certificate = form.ssl_certificate
      if (form.ssl_private_key) body.ssl_private_key = form.ssl_private_key
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

  async function regenerateSsl(key) {
    const server = data.servers[key]
    if (!server) return
    busy.value = true
    pendingAction.value = { kind: 'ssl-regenerate', key }
    try {
      const body = {
        app_name: server.APP_NAME || '',
        domain_name: server.DOMAIN_NAME || '',
        server_path: server.SERVER_PATH || '',
        php_version: versionFromContainer(server.CONTAINER_PHP_VERSION || ''),
        enabled: isServerEnabled(server),
        ssl_enabled: true,
      }
      const result = await apiSend('PUT', `/api/servers/${key}`, body)
      if (result.bootstrap) applyBootstrap(result.bootstrap)
      showToast('success', t('flash.ssl_regenerated'))
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      busy.value = false
      pendingAction.value = null
    }
  }

  async function toggleServerEnabled(key) {
    const server = data.servers[key]
    if (!server) return
    const currentlyEnabled = isServerEnabled(server)
    busy.value = true
    pendingAction.value = { kind: 'toggle', key }
    try {
      const body = {
        app_name: server.APP_NAME || '',
        domain_name: server.DOMAIN_NAME || '',
        server_path: server.SERVER_PATH || '',
        php_version: versionFromContainer(server.CONTAINER_PHP_VERSION || ''),
        enabled: !currentlyEnabled,
      }
      const result = await apiSend('PUT', `/api/servers/${key}`, body)
      if (result.bootstrap) applyBootstrap(result.bootstrap)
      showToast(
        'success',
        t(currentlyEnabled ? 'flash.server_disabled' : 'flash.server_enabled'),
      )
      try {
        await apiSend('POST', '/api/nginx/reload', {})
      } catch (reloadError) {
        showToast('failure', translateApiError(reloadError))
      }
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
      const write = beginHostsWrite()
      const result = await apiSend('DELETE', `/api/domains/extra/${encodeURIComponent(domain)}`, {
        hosts_write_token: write.token,
      })
      if (domainEditingKey.value === key) closeDomainModal()
      if (result.bootstrap) applyBootstrap(result.bootstrap)
      else await loadBootstrap({ silent: true })
      showToast('success', trKey(result.message_key || 'hosts.domain_removed'))
      if (data.hosts_write_enabled) {
        await finishHostsWrite(result, write)
      }
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
    const previousUpdatedAt = data.nginx_status?.updated_at || ''
    try {
      await apiSend('POST', '/api/nginx/reload', {})
      showToast('success', t('reload.waiting'))

      // Fast-poll /api/nginx/status until updated_at changes or timeout.
      const POLL_INTERVAL = 1500
      const POLL_TIMEOUT = 30000
      const started = Date.now()
      const poll = setInterval(async () => {
        try {
          const statusResult = await apiGet('/api/nginx/status')
          const status = statusResult.nginx_status
          if (status && status.updated_at && status.updated_at !== previousUpdatedAt) {
            clearInterval(poll)
            data.nginx_status = status
            const ok = status.status === 'success' || status.ok === true
            const raw = status.message || ''
            const key = reloadMessageKeys[raw]
            const msg = key ? t(key) : raw || t('reload.unknown')
            showToast(ok ? 'success' : 'failure', msg)
            busy.value = false
            pendingAction.value = null
          } else if (Date.now() - started > POLL_TIMEOUT) {
            clearInterval(poll)
            showToast('failure', t('reload.timeout'))
            busy.value = false
            pendingAction.value = null
          }
        } catch (_) {
          // ignore transient poll errors
        }
      }, POLL_INTERVAL)
    } catch (error) {
      showToast('failure', translateApiError(error))
      busy.value = false
      pendingAction.value = null
    }
  }

  /** Queue a PHP container lifecycle action; status updates via bootstrap poll. */
  async function phpAction(service, action) {
    const target = data.php_controllers?.targets?.[service]
    pendingAction.value = { kind: 'php', service, action }
    try {
      const result = await apiSend('POST', `/api/php-controllers/${service}/${action}`, {})
      toastFromResult(result)
      if (result.php_controllers) data.php_controllers = result.php_controllers
      await waitForPullProgress({
        runtime: 'php',
        service,
        action,
        label: target?.label || service,
      })
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      pendingAction.value = null
    }
  }

  async function startPhpControllerDaemon() {
    pendingAction.value = { kind: 'php-daemon', action: 'start' }
    try {
      const result = await apiSend('POST', '/api/php-controller/start', {})
      toastFromResult(result)
      if (result.php_controller_daemon) data.php_controller_daemon = result.php_controller_daemon
      await loadBootstrap({ silent: true })
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      pendingAction.value = null
    }
  }

  /** Queue an infra container lifecycle action; status updates via bootstrap poll. */
  async function infraAction(service, action) {
    const target = data.infra_services?.targets?.[service]
    if (action === 'delete') {
      if (
        !confirm(
          t('services.delete_confirm', {
            service: target?.label || service,
            container: target?.container || service,
          }),
        )
      ) {
        return
      }
    }
    if (action === 'delete-image') {
      if (
        !confirm(
          t('services.delete_image_confirm', {
            service: target?.label || service,
            image: target?.image || service,
          }),
        )
      ) {
        return
      }
    }
    pendingAction.value = { kind: 'infra', service, action }
    try {
      const result = await apiSend('POST', `/api/infra-services/${service}/${action}`, {})
      toastFromResult(result)
      if (result.infra_services) data.infra_services = result.infra_services
      if (action === 'delete' || action === 'delete-image') return
      await waitForPullProgress({
        runtime: 'infra',
        service,
        action,
        label: target?.label || service,
      })
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      pendingAction.value = null
    }
  }

  /** Queue a Supervisor container lifecycle action; status updates via bootstrap poll. */
  async function supervisorAction(service, action) {
    const target = data.supervisor_services?.targets?.[service]
    pendingAction.value = { kind: 'supervisor', service, action }
    try {
      const result = await apiSend('POST', `/api/supervisor/${service}/${action}`, {})
      toastFromResult(result)
      if (result.supervisor_services) data.supervisor_services = result.supervisor_services
      await waitForPullProgress({
        runtime: 'supervisor',
        service,
        action,
        label: target?.label || service,
      })
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      pendingAction.value = null
    }
  }

  function openHostsDomainAdd() {
    domainModalMode.value = 'add'
    domainEditingKey.value = null
    domainFieldErrors.value = {}
    domainForm.domain_label = ''
    domainForm.domain_tld = '.test'
    domainForm.domain_custom = ''
    domainModalOpen.value = true
  }

  function openDomainEdit(key) {
    const row = domainEntries.value.find((item) => item.key === key)
    if (!row) return
    domainModalMode.value = 'edit'
    domainEditingKey.value = key
    domainFieldErrors.value = {}
    const parsed = parseLocalDomain(row.domain_name || '')
    domainForm.domain_label = parsed.name
    domainForm.domain_tld = parsed.tld
    domainForm.domain_custom = parsed.custom
    domainModalOpen.value = true
  }

  function closeDomainModal() {
    domainModalOpen.value = false
    domainModalMode.value = 'add'
    domainEditingKey.value = null
    domainFieldErrors.value = {}
    domainForm.domain_label = ''
    domainForm.domain_tld = '.test'
    domainForm.domain_custom = ''
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

  function pullProgressLabel(runtime, service, composeFile) {
    if (runtime === 'compose') return composeFile || service
    if (runtime === 'infra') return data.infra_services?.targets?.[service]?.label || service
    if (runtime === 'php') return data.php_controllers?.targets?.[service]?.label || service
    if (runtime === 'supervisor') return data.supervisor_services?.targets?.[service]?.label || service
    return service
  }

  function pullProgressBootstrapState(job) {
    if (job.runtime === 'compose') {
      const row = data.infra_services?.compose_files?.find((file) => file.name === job.composeFile)
      return row?.state || 'busy'
    }
    if (job.runtime === 'infra') return infraServiceState(job.service)
    if (job.runtime === 'php') return phpServiceState(job.service)
    if (job.runtime === 'supervisor') return supervisorServiceState(job.service)
    return job.state || 'busy'
  }

  function pullProgressLogsUrl(job) {
    if (job.runtime === 'compose') {
      return `/api/infra-services/compose-files/${encodeURIComponent(job.composeFile)}/action-logs`
    }
    if (job.runtime === 'infra') {
      return `/api/infra-services/${job.service}/action-logs`
    }
    if (job.runtime === 'php') {
      return `/api/php-controllers/${job.service}/action-logs`
    }
    if (job.runtime === 'supervisor') {
      return `/api/supervisor/${job.service}/action-logs`
    }
    return null
  }

  function stopPullProgressTimer() {
    if (pullProgressTimer) {
      clearInterval(pullProgressTimer)
      pullProgressTimer = null
    }
  }

  function resolvePullProgressState(job, liveState, logState) {
    if (job.runtime === 'compose') {
      if (liveState === 'busy' || logState === 'busy') {
        job.sawBusy = true
        return 'busy'
      }
      if (liveState === 'error' || logState === 'error') return 'error'
      const pending =
        pendingAction.value?.kind === 'compose-file' &&
        pendingAction.value?.name === job.composeFile
      if (pending && !job.sawBusy) return 'busy'
      return logState || liveState || 'busy'
    }
    if (liveState && liveState !== 'busy') {
      return liveState
    }
    if (logState && logState !== 'busy') {
      return logState
    }
    return liveState || logState || 'busy'
  }

  async function pollPullProgress() {
    const job = pullProgress.value
    if (!job) return

    await loadBootstrap({ silent: true })
    const liveState = pullProgressBootstrapState(job)

    const url = pullProgressLogsUrl(job)
    let logState = ''
    if (url) {
      try {
        const result = await apiGet(url)
        const logs = result.logs || {}
        job.content = logs.content || logs.recreate_log || logs.create_log || ''
        logState = logs.state || ''
      } catch (_) {
        // keep previous output
      }
    }

    job.state = resolvePullProgressState(job, liveState, logState)
    job.loading = false
  }

  function startPullProgress({ runtime, service = '', action, label, composeFile = '' }) {
    stopPullProgressTimer()
    pullProgress.value = {
      runtime,
      service,
      composeFile,
      action,
      label: label || pullProgressLabel(runtime, service, composeFile),
      dismissed: false,
      state: 'busy',
      content: '',
      loading: true,
      sawBusy: false,
    }
    pollPullProgress()
    pullProgressTimer = setInterval(pollPullProgress, PULL_PROGRESS_POLL_MS)
  }

  function dismissPullProgress() {
    if (pullProgress.value) pullProgress.value.dismissed = true
  }

  async function waitForPullProgress(job) {
    if (!PULL_TRACKED_ACTIONS.has(job.action)) return

    startPullProgress(job)

    const deadline = Date.now() + 120_000
    while (Date.now() < deadline) {
      const current = pullProgress.value
      if (!current) break
      if (current.state !== 'busy') break
      await sleep(PULL_PROGRESS_POLL_MS)
      await pollPullProgress()
    }
    await pollPullProgress()
    stopPullProgressTimer()
    for (let i = 0; i < 4; i += 1) {
      if (!pullProgress.value || pullProgress.value.dismissed) break
      if (pullProgress.value.state !== 'busy') break
      await sleep(1200)
      await pollPullProgress()
    }
    await pollPullProgress()
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
        if (status.status === 'busy' || (pendingSync && status.status === 'success')) {
          latestBusy = status.status === 'busy' ? status : latestBusy
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

  function beginHostsWrite() {
    const previousUpdatedAt = data.hosts_status?.updated_at || null
    const token = newHostsWriteToken()
    const launched = data.hosts_write_enabled ? launchHostsWriteProtocol(window, token) : false
    return { token, launched, previousUpdatedAt }
  }

  async function finishHostsWrite(result, write = {}) {
    const previousUpdatedAt = write.previousUpdatedAt || data.hosts_status?.updated_at || null
    const launched = !!write.launched
    if (result?.bootstrap) applyBootstrap(result.bootstrap)
    else if (result?.hosts_status) data.hosts_status = result.hosts_status

    hostsProgress.value = {
      attempt: 0,
      maxAttempts: 45,
      message_key: launched ? 'hosts.progress_protocol' : 'hosts.progress_starting',
    }

    try {
      const outcome = await waitForHostsResult(previousUpdatedAt, 45)
      const status = outcome?.status || null
      await loadBootstrap({ silent: true })
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
    const write = beginHostsWrite()
    try {
      let result
      const body = {
        domain_name: composeLocalDomain(
          domainForm.domain_label,
          domainForm.domain_tld,
          domainForm.domain_custom,
        ),
        hosts_write_token: write.token,
      }
      if (mode === 'add') {
        result = await apiSend('POST', '/api/domains', body)
      } else if (String(editingKeySnapshot).startsWith('hosts:')) {
        const current = String(editingKeySnapshot).slice('hosts:'.length)
        result = await apiSend('PUT', `/api/domains/extra/${encodeURIComponent(current)}`, body)
      } else {
        result = await apiSend('PUT', `/api/domains/${editingKeySnapshot}`, body)
      }
      closeDomainModal()
      if (result.bootstrap) applyBootstrap(result.bootstrap)
      else await loadBootstrap({ silent: true })
      showToast('success', trKey(result.message_key || 'hosts.domain_added'))
      if (data.hosts_write_enabled) {
        await finishHostsWrite(result, write)
      }
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

  async function writeDomainHostsAdmin(domainName) {
    if (!data.hosts_write_enabled) {
      showToast('failure', t('error.hosts_write_disabled_remote'))
      return
    }
    const domain = String(domainName || '').toLowerCase()
    if (!domain) return
    busy.value = true
    pendingAction.value = { kind: 'hosts-admin', domain }
    const write = beginHostsWrite()
    try {
      const result = await apiSend('POST', '/api/hosts/sync', {
        force_admin: true,
        domain_name: domain,
        hosts_write_token: write.token,
      })
      data.pending_sync = !!result.pending_sync
      await finishHostsWrite(
        {
          ...result,
          manual: result.manual || data.hosts_status?.manual || null,
        },
        write,
      )
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
    if (state === 'stopped') return 'state-stopped'
    if (state === 'error' || state === 'busy') return `state-${state}`
    if (state === 'not_created') return 'state-idle'
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

  /** True while any Docker-managed service reports busy (drives faster status polling). */
  const dockerStatusBusy = computed(() => {
    const groups = [data.php_controllers?.statuses, data.infra_services?.statuses, data.supervisor_services?.statuses]
    for (const statuses of groups) {
      if (!statuses) continue
      for (const row of Object.values(statuses)) {
        if (row?.state === 'busy') return true
      }
    }
    for (const file of data.infra_services?.compose_files || []) {
      if (file.state === 'busy') return true
    }
    const nginx = data.nginx_management
    if (nginx?.state === 'busy') return true
    return false
  })

  const phpControllerDaemonRunning = computed(
    () => data.php_controller_daemon?.state === 'running',
  )

  function phpServiceState(service) {
    return data.php_controllers.statuses[service]?.state || 'not_created'
  }

  function phpActionEnabled(service, action) {
    if (isPending('php', { service })) return false
    if (!phpControllerDaemonRunning.value) return false
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
    if (isPending('infra', { service })) return false
    if (!phpControllerDaemonRunning.value) return false
    const state = infraServiceState(service)
    const target = data.infra_services.targets[service]
    if (action === 'create') {
      return state === 'not_created' && target?.profile != null
    }
    if (action === 'pull-recreate') {
      return state === 'running' || state === 'stopped'
    }
    if (action === 'delete') {
      return state === 'running' || state === 'stopped'
    }
    if (action === 'delete-image') {
      return state === 'not_created' && !!target?.image_present
    }
    if (state === 'busy' || state === 'error' || state === 'not_created') return false
    if (action === 'start') return state === 'stopped'
    if (action === 'stop' || action === 'restart') return state === 'running'
    return false
  }

  function showInfraCreateHint(service, target) {
    return infraServiceState(service) === 'not_created' && target.profile !== null
  }

  function supervisorServiceState(service) {
    return data.supervisor_services.statuses[service]?.state || 'not_created'
  }

  function supervisorActionEnabled(service, action) {
    if (isPending('supervisor', { service })) return false
    if (!phpControllerDaemonRunning.value) return false
    const state = supervisorServiceState(service)
    if (action === 'create') return state === 'not_created'
    if (state === 'busy' || state === 'error' || state === 'not_created') return false
    if (action === 'start') return state === 'stopped'
    if (action === 'stop' || action === 'restart') return state === 'running'
    return false
  }

  function composeYamlActionEnabled(item, action) {
    if (item?.runtime !== 'compose') return false
    if (isPending('compose-file', { name: item.name, action })) return false
    if (!phpControllerDaemonRunning.value) return false
    const state = item.state || 'not_created'
    if (action === 'create') return state === 'not_created'
    if (action === 'recreate') return state === 'running' || state === 'stopped' || state === 'error'
    if (action === 'delete') return state === 'running' || state === 'stopped'
    if (action === 'delete-image') return state === 'not_created' && !!item.image_present
    if (state === 'busy' || state === 'error' || state === 'not_created') return false
    if (action === 'start') return state === 'stopped'
    if (action === 'stop' || action === 'restart') return state === 'running'
    return false
  }

  async function composeYamlAction(item, action) {
    if (item?.runtime !== 'compose') return
    const label = item.compose_services?.[0]?.name || item.name.replace(/\.ya?ml$/i, '')
    const container = item.container || item.compose_services?.[0]?.container || label
    if (action === 'delete') {
      if (
        !confirm(
          t('services.delete_confirm', {
            service: label,
            container,
          }),
        )
      ) {
        return
      }
    }
    if (action === 'delete-image') {
      if (
        !confirm(
          t('services.delete_image_confirm', {
            service: label,
            image: item.image || label,
          }),
        )
      ) {
        return
      }
    }
    pendingAction.value = { kind: 'compose-file', name: item.name, action }
    try {
      const result = await apiSend(
        'POST',
        `/api/infra-services/compose-files/${encodeURIComponent(item.name)}/${action}`,
        {},
      )
      toastFromResult(result)
      if (result.infra_services) data.infra_services = result.infra_services
      if (action === 'delete' || action === 'delete-image' || action === 'stop' || action === 'restart') {
        await loadBootstrap({ silent: true })
        return result
      }
      await waitForPullProgress({
        runtime: 'compose',
        composeFile: item.name,
        action,
        label: item.name,
      })
      const deadline = Date.now() + 90_000
      while (Date.now() < deadline) {
        await loadBootstrap({ silent: true })
        const row = data.infra_services?.compose_files?.find((file) => file.name === item.name)
        if ((row?.state || 'busy') !== 'busy') break
        await sleep(1500)
      }
      await loadBootstrap({ silent: true })
      return result
    } catch (error) {
      showToast('failure', translateApiError(error))
    } finally {
      pendingAction.value = null
    }
  }

  function composeTabActionEnabled(item, action) {
    if (!item?.runtime) return false
    if (item.runtime === 'compose') {
      if (action === 'recreate') return composeYamlActionEnabled(item, 'recreate')
      return composeYamlActionEnabled(item, action)
    }
    if (!item.service) return false
    if (action === 'create') {
      if (item.runtime === 'infra') return infraActionEnabled(item.service, 'create')
      if (item.runtime === 'supervisor') return supervisorActionEnabled(item.service, 'create')
    }
    if (action === 'start') {
      if (item.runtime === 'infra') return infraActionEnabled(item.service, 'start')
      if (item.runtime === 'supervisor') return supervisorActionEnabled(item.service, 'start')
    }
    if (action === 'pull-recreate' && item.runtime === 'infra' && item.pull_recreate) {
      return infraActionEnabled(item.service, 'pull-recreate')
    }
    return false
  }

  async function composeTabAction(item, action) {
    if (item.runtime === 'compose') return composeYamlAction(item, action)
    if (!item?.runtime || !item.service) return
    if (item.runtime === 'infra') return infraAction(item.service, action)
    if (item.runtime === 'supervisor') return supervisorAction(item.service, action)
  }

  function composeFileState(itemOrRuntime, service) {
    if (itemOrRuntime && typeof itemOrRuntime === 'object') {
      const item = itemOrRuntime
      if (item.runtime === 'compose') return item.state || 'not_created'
      if (!item.runtime || !item.service) return 'not_created'
      return composeFileState(item.runtime, item.service)
    }
    const runtime = itemOrRuntime
    if (runtime === 'infra') return infraServiceState(service)
    if (runtime === 'php') return phpServiceState(service)
    if (runtime === 'supervisor') return supervisorServiceState(service)
    return 'not_created'
  }

  function composeFileActionEnabled(item, action) {
    return composeTabActionEnabled(item, action)
  }

  async function composeFileAction(item, action) {
    return composeTabAction(item, action)
  }

  function showComposeCreateHint(item) {
    if (!item?.runtime) return false
    if (item.runtime === 'compose') {
      return (item.state || 'not_created') === 'not_created'
    }
    if (!item.service) return false
    return composeFileState(item) === 'not_created'
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
    dockerStatusBusy,
    phpControllerDaemonRunning,
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
    pullProgress,
    dismissPullProgress,
    serverEntries,
    domainEntries,
    versionLabel,
    loadBootstrap,
    logout,
    openAddModal,
    openHostsDomainAdd,
    closeModal,
    startEdit,
    saveServer,
    deleteServer,
    regenerateSsl,
    toggleServerEnabled,
    isServerEnabled,
    deleteDomain,
    reloadNginx,
    phpAction,
    startPhpControllerDaemon,
    infraAction,
    supervisorAction,
    openDomainEdit,
    closeDomainModal,
    saveDomain,
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
    supervisorServiceState,
    supervisorActionEnabled,
    composeFileState,
    composeFileActionEnabled,
    composeFileAction,
    composeYamlAction,
    composeYamlActionEnabled,
    composeYamlActionEnabled,
    composeTabAction,
    composeTabActionEnabled,
    showComposeCreateHint,
    isPending,
    showToast,
    dismissToast,
    translateApiError,
  }
}
