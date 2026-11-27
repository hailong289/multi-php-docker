const { invoke } = window.__TAURI__.core
const { listen } = window.__TAURI__.event

const $ = (id) => document.getElementById(id)
const params = new URLSearchParams(location.search)

let status = null
let running = null // compose action in progress
let dockerStarting = false
let firstStatus = true
let updateVersion = null
let following = null // service whose log is streamed
let stats = {} // container name → { cpu, mem }

// Last result / pick error as functions, so switching language re-translates them.
let lastResult = null
let pickError = null

function setRow(prefix, tone, text) {
  $(`${prefix}-step`).dataset.state = tone
  $(`${prefix}-text`).textContent = text
}

function render() {
  const s = status
  if (!s) return
  $('log-result').textContent = lastResult ? lastResult.text() : ''
  $('pick-error').textContent = pickError ? pickError() : ''
  $('pick').hidden = !!s.project_dir
  $('main').hidden = !s.project_dir
  if (!s.project_dir) return
  $('dir').textContent = s.source_dir || s.project_dir
  $('dir-label').textContent = t(s.source_dir ? 'dir.source' : 'dir.label')
  $('switch-mode').textContent = t(s.source_dir ? 'dir.use_repo' : 'dir.use_source')

  const busy = !!running || s.busy
  if (!s.docker_found) setRow('docker', 'bad', t('docker.missing'))
  else if (s.docker_running) setRow('docker', 'ok', t('docker.running'))
  else setRow('docker', dockerStarting ? 'wait' : 'off', t(dockerStarting ? 'docker.starting' : 'docker.off'))
  $('docker-download').hidden = s.docker_found
  $('docker-open').hidden = !s.docker_found || s.docker_running
  $('docker-open').disabled = dockerStarting

  if (s.manager === 'manager') setRow('manager', 'ok', t('manager.ready'))
  else if (s.manager === 'other')
    setRow('manager', 'bad', s.port_owner ? t('manager.port_busy_by', { owner: s.port_owner }) : t('manager.port_busy'))
  else if (running === 'restart') setRow('manager', 'wait', t('manager.starting'))
  else if (running === 'up' || running === 'build') setRow('manager', 'wait', t(running === 'build' ? 'manager.building' : 'manager.starting'))
  else setRow('manager', 'off', t(s.docker_running ? 'manager.off' : 'manager.off_need_docker'))
  const canStart = s.docker_running && s.manager === 'closed'
  $('up-btn').hidden = !canStart
  $('build-btn').hidden = !canStart
  $('up-btn').disabled = busy
  $('build-btn').disabled = busy
  $('restart-btn').hidden = s.manager !== 'manager'
  $('restart-btn').disabled = busy
  $('change-dir').disabled = busy
  $('switch-mode').disabled = busy
  $('prune-btn').disabled = busy || !s.docker_running
  $('open-btn').disabled = s.manager !== 'manager'
  $('open-hint').textContent =
    s.manager === 'manager' ? '' : t(s.docker_running ? 'hint.start_manager' : 'hint.start_docker')
  renderServices(s.services)
  if (updateVersion) $('update-text').textContent = t('update.available', { version: updateVersion })
}

function el(tag, className, text) {
  const node = document.createElement(tag)
  node.className = className
  if (text !== undefined) node.textContent = text
  return node
}

function linkButton(text, onClick, disabled = false) {
  const button = el('button', 'link', text)
  button.type = 'button'
  button.disabled = disabled
  button.addEventListener('click', onClick)
  return button
}

function renderServices(services) {
  $('services').hidden = !services.length
  const busy = !!running || status.busy
  $('service-list').replaceChildren(
    ...services.map((svc) => {
      const up = svc.state === 'running'
      const li = document.createElement('li')
      li.dataset.state = up ? 'ok' : svc.state === 'exited' || svc.state === 'dead' ? 'bad' : 'wait'
      const usage = up && stats[svc.container]
      const actions = el('span', 'svc-actions')
      actions.append(
        up
          ? linkButton(t('services.restart'), () => runService(svc.name, 'restart'), busy)
          : linkButton(t('services.start'), () => runService(svc.name, 'start'), busy),
      )
      if (up) actions.append(linkButton(t('services.terminal'), () => call('open_terminal', { service: svc.name })))
      actions.append(linkButton(t('services.logs'), () => followLogs(svc.name)))
      li.append(
        el('span', 'svc-name', svc.name),
        el('span', 'svc-status', svc.status),
        el('span', 'svc-usage', usage ? `${usage.cpu} · ${usage.mem}` : ''),
        actions,
      )
      return li
    }),
  )
}

// Commands whose only feedback is an error.
async function call(cmd, args) {
  try {
    await invoke(cmd, args)
  } catch (error) {
    showResult(() => errorText(error), true)
  }
}

async function followLogs(name) {
  await invoke('stop_logs')
  following = name
  $('log').textContent = ''
  showResult(null, false)
  $('follow-text').textContent = t('services.following', { name })
  $('follow-bar').hidden = false
  try {
    await invoke('follow_logs', { service: name })
  } catch (error) {
    stopFollowing()
    showResult(() => errorText(error), true)
  }
}

function stopFollowing() {
  following = null
  $('follow-bar').hidden = true
  invoke('stop_logs')
}

async function runService(service, action) {
  if (running) return
  stopFollowing()
  $('log').textContent = ''
  running = `service-${action}`
  render()
  const label = t(`action.${action}`)
  try {
    const code = await invoke('service_action', { service, action })
    showResult(
      () => (code === 0 ? t('result.service_done', { action: label, name: service }) : t('result.service_failed', { action: label, name: service, code })),
      code !== 0,
    )
  } catch (error) {
    showResult(() => errorText(error), true)
  }
  running = null
  await refresh()
}

// docker stats takes ~2 s per sample, so it runs on its own slower loop, never overlapping.
async function refreshStats() {
  const containers = (status?.services || []).filter((s) => s.state === 'running').map((s) => s.container)
  if (containers.length && !document.hidden) {
    try {
      stats = Object.fromEntries((await invoke('stats', { containers })).map((s) => [s.container, s]))
      render()
    } catch (_) {}
  }
  setTimeout(refreshStats, 5000)
}

async function refresh() {
  try {
    status = await invoke('status')
  } catch (error) {
    return
  }
  if (status.docker_running) dockerStarting = false
  render()
  if (firstStatus) {
    firstStatus = false
    onFirstStatus()
    refreshStats()
  }
}

// Follow-ups from the tray menu arrive as ?action=…; a plain launch with the Manager up goes straight in.
function onFirstStatus() {
  const action = params.get('action')
  history.replaceState(null, '', location.pathname)
  if (action === 'pick') return pickDir()
  if (action === 'up' || action === 'stop' || action === 'restart') {
    if (!status.project_dir) return
    if (!status.docker_running) return showResult(() => t('result.docker_off'), true)
    if (action === 'up' && status.manager === 'manager') return showResult(() => t('result.already_running'), false)
    return run([action])
  }
  if (!params.has('stay') && status.manager === 'manager') invoke('open_manager')
}

function showLog() {
  $('log-wrap').hidden = false
}

function appendLog(line) {
  showLog()
  const log = $('log')
  log.textContent += `${line}\n`
  log.scrollTop = log.scrollHeight
}

function showResult(text, isError) {
  showLog()
  lastResult = text ? { text } : null
  const el = $('log-result')
  el.textContent = text ? text() : ''
  el.className = `log-result ${isError ? 'error' : 'ok'}`
}

async function run(actions) {
  if (running) return
  stopFollowing()
  $('log').textContent = ''
  showResult(null, false)
  for (const action of actions) {
    running = action
    render()
    try {
      const code = await invoke('compose', { action })
      if (code !== 0) {
        showResult(() => t('result.failed', { action: t(`action.${action}`), code }), true)
        break
      }
      showResult(() => t('result.done', { action: t(`action.${action}`) }), false)
    } catch (error) {
      showResult(() => errorText(error), true)
      break
    }
  }
  running = null
  await refresh()
}

// kind: 'source' (bundled stack on the user's code) or 'repo' (an existing multi-php-docker checkout).
async function pickDir(kind = status?.project_dir && !status.source_dir ? 'repo' : 'source') {
  $('pick-error').hidden = true
  pickError = null
  try {
    const dir = await invoke(kind === 'repo' ? 'pick_project_dir' : 'pick_source_dir')
    if (dir) await refresh()
  } catch (error) {
    $('pick').hidden = false
    $('main').hidden = true
    pickError = () => errorText(error)
    $('pick-error').textContent = pickError()
    $('pick-error').hidden = false
  }
}

$('pick-btn').addEventListener('click', () => pickDir('source'))
$('pick-repo').addEventListener('click', () => pickDir('repo'))
$('change-dir').addEventListener('click', () => pickDir())
$('switch-mode').addEventListener('click', () => pickDir(status.source_dir ? 'repo' : 'source'))
$('docker-open').addEventListener('click', async () => {
  dockerStarting = true
  render()
  try {
    await invoke('open_docker')
  } catch (error) {
    dockerStarting = false
    showResult(() => errorText(error), true)
    render()
  }
})
$('up-btn').addEventListener('click', () => run(['up']))
$('build-btn').addEventListener('click', () => run(['build', 'up']))
$('restart-btn').addEventListener('click', () => run(['restart']))
$('follow-stop').addEventListener('click', stopFollowing)
$('open-folder').addEventListener('click', () => call('open_project', { with: 'folder' }))
$('open-code').addEventListener('click', () => call('open_project', { with: 'code' }))
$('prune-btn').addEventListener('click', async () => {
  if (running) return
  stopFollowing()
  $('log').textContent = ''
  running = 'prune'
  render()
  try {
    const code = await invoke('prune')
    if (code === null) $('log-wrap').hidden = !$('log').textContent
    else if (code === 0) showResult(() => t('result.done', { action: t('action.prune') }), false)
    else showResult(() => t('result.failed', { action: t('action.prune'), code }), true)
  } catch (error) {
    showResult(() => errorText(error), true)
  }
  running = null
  await refresh()
})
$('update-btn').addEventListener('click', async () => {
  $('update-btn').disabled = true
  $('update-text').textContent = t('update.installing')
  try {
    await invoke('install_update') // restarts the app on success
  } catch (error) {
    $('update-btn').disabled = false
    $('update-text').textContent = t('err.update_failed', { detail: String(error) })
  }
})
$('autostart').addEventListener('change', async (event) => {
  try {
    await invoke('set_autostart', { on: event.target.checked })
  } catch (error) {
    event.target.checked = !event.target.checked
    showResult(() => String(error), true)
  }
})
$('open-btn').addEventListener('click', () => invoke('open_manager'))
document.querySelectorAll('[data-lang]').forEach((el) =>
  el.addEventListener('click', () => {
    setLang(el.dataset.lang)
    invoke('set_lang', { lang })
    render()
  }),
)

applyStatic()
invoke('set_lang', { lang })
invoke('autostart_enabled').then((on) => ($('autostart').checked = on))
invoke('check_update').then((version) => {
  updateVersion = version
  $('update').hidden = !version
  if (version) $('update-text').textContent = t('update.available', { version })
})

listen('compose-log', (event) => appendLog(event.payload))
listen('follow-log', (event) => following && appendLog(event.payload))
refresh()
// Only checks state; the app never starts anything on its own.
setInterval(refresh, 3000)
