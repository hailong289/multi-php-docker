const { invoke } = window.__TAURI__.core
const { listen } = window.__TAURI__.event

const $ = (id) => document.getElementById(id)
const params = new URLSearchParams(location.search)

let status = null
let running = null // compose action in progress
let dockerStarting = false
let firstStatus = true

const ACTION_LABEL = { up: 'Khởi động', build: 'Build', stop: 'Dừng stack' }

function setRow(prefix, tone, text) {
  $(`${prefix}-step`).dataset.state = tone
  $(`${prefix}-text`).textContent = text
}

function render() {
  const s = status
  $('pick').hidden = !!s.project_dir
  $('main').hidden = !s.project_dir
  if (!s.project_dir) return
  $('dir').textContent = s.project_dir

  const busy = !!running || s.busy
  if (!s.docker_found) setRow('docker', 'bad', 'Chưa cài Docker')
  else if (s.docker_running) setRow('docker', 'ok', 'Đang chạy')
  else setRow('docker', dockerStarting ? 'wait' : 'off', dockerStarting ? 'Đang khởi động… (có thể mất ~30 giây)' : 'Đang tắt')
  $('docker-download').hidden = s.docker_found
  $('docker-open').hidden = !s.docker_found || s.docker_running
  $('docker-open').disabled = dockerStarting

  if (s.manager === 'manager') setRow('manager', 'ok', 'Sẵn sàng')
  else if (s.manager === 'other') setRow('manager', 'bad', 'Cổng 8080 đang bị chương trình khác dùng')
  else if (running === 'up' || running === 'build') setRow('manager', 'wait', running === 'build' ? 'Đang build image…' : 'Đang khởi động…')
  else setRow('manager', 'off', s.docker_running ? 'Chưa chạy' : 'Chưa chạy · cần Docker trước')
  const canStart = s.docker_running && s.manager === 'closed'
  $('up-btn').hidden = !canStart
  $('build-btn').hidden = !canStart
  $('up-btn').disabled = busy
  $('build-btn').disabled = busy
  $('change-dir').disabled = busy
  $('open-btn').disabled = s.manager !== 'manager'
  $('open-hint').textContent =
    s.manager === 'manager' ? '' : s.docker_running ? 'Khởi động Manager để mở.' : 'Bật Docker, rồi khởi động Manager.'
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
  }
}

// Follow-ups from the tray menu arrive as ?action=…; a plain launch with the Manager up goes straight in.
function onFirstStatus() {
  const action = params.get('action')
  history.replaceState(null, '', location.pathname)
  if (action === 'pick') return pickDir()
  if (action === 'up' || action === 'stop') {
    if (!status.project_dir) return
    if (!status.docker_running) return showResult('Docker đang tắt: mở Docker trước.', true)
    if (action === 'up' && status.manager === 'manager') return showResult('Manager đã chạy sẵn.', false)
    return run(action === 'up' ? ['up'] : ['stop'])
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
  const el = $('log-result')
  el.textContent = text
  el.className = `log-result ${isError ? 'error' : 'ok'}`
}

async function run(actions) {
  if (running) return
  $('log').textContent = ''
  showResult('', false)
  for (const action of actions) {
    running = action
    render()
    try {
      const code = await invoke('compose', { action })
      if (code !== 0) {
        showResult(`${ACTION_LABEL[action]} lỗi (mã ${code}). Xem log ở trên, sửa rồi bấm lại.`, true)
        break
      }
      showResult(`${ACTION_LABEL[action]} xong.`, false)
    } catch (error) {
      showResult(String(error), true)
      break
    }
  }
  running = null
  await refresh()
}

async function pickDir() {
  $('pick-error').hidden = true
  try {
    const dir = await invoke('pick_project_dir')
    if (dir) await refresh()
  } catch (error) {
    $('pick').hidden = false
    $('main').hidden = true
    $('pick-error').textContent = String(error)
    $('pick-error').hidden = false
  }
}

$('pick-btn').addEventListener('click', pickDir)
$('change-dir').addEventListener('click', pickDir)
$('docker-open').addEventListener('click', async () => {
  dockerStarting = true
  render()
  try {
    await invoke('open_docker')
  } catch (error) {
    dockerStarting = false
    showResult(String(error), true)
    render()
  }
})
$('up-btn').addEventListener('click', () => run(['up']))
$('build-btn').addEventListener('click', () => run(['build', 'up']))
$('open-btn').addEventListener('click', () => invoke('open_manager'))

listen('compose-log', (event) => appendLog(event.payload))
refresh()
// Only checks state; the app never starts anything on its own.
setInterval(refresh, 3000)
