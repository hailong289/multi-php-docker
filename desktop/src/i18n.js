// Launcher strings. Rust errors arrive as "key" or "key|detail" and map to err.<key>.
const MESSAGES = {
  vi: {
    tagline: 'Môi trường PHP chạy bằng Docker',
    'pick.title': 'Chọn thư mục source',
    'pick.desc': 'Thư mục chứa code các project PHP, mỗi project một thư mục con (ví dụ <code>~/code</code>). Docker stack đã có sẵn trong app.',
    'pick.button': 'Chọn thư mục source…',
    'pick.repo': 'Đã có repo multi-php-docker? Dùng repo đó',
    'docker.download': 'Tải Docker Desktop',
    'docker.start': 'Khởi động Docker',
    'docker.missing': 'Chưa cài Docker',
    'docker.running': 'Đang chạy',
    'docker.starting': 'Đang khởi động… (có thể mất ~30 giây)',
    'docker.off': 'Đang tắt',
    'manager.start': 'Khởi động',
    'manager.build': 'Build lại',
    'manager.build_title': 'docker compose build, rồi khởi động',
    'manager.ready': 'Sẵn sàng',
    'manager.port_busy': 'Cổng 8080 đang bị chương trình khác dùng',
    'manager.building': 'Đang build image…',
    'manager.starting': 'Đang khởi động…',
    'manager.off': 'Chưa chạy',
    'manager.off_need_docker': 'Chưa chạy · cần Docker trước',
    open: 'Mở Manager',
    'hint.start_manager': 'Khởi động Manager để mở.',
    'hint.start_docker': 'Bật Docker, rồi khởi động Manager.',
    'dir.label': 'Thư mục',
    'dir.change': 'Đổi',
    'dir.source': 'Source',
    'dir.use_repo': 'Dùng repo có sẵn…',
    'dir.use_source': 'Chỉ dùng thư mục source…',
    'action.up': 'Khởi động',
    'action.build': 'Build',
    'action.stop': 'Dừng stack',
    'result.done': '{action} xong.',
    'result.failed': '{action} lỗi (mã {code}). Xem log ở trên, sửa rồi bấm lại.',
    'result.docker_off': 'Docker đang tắt: khởi động Docker trước.',
    'result.already_running': 'Manager đã chạy sẵn.',
    'err.not_project_dir': '{detail} không có docker-compose.yml của Multi PHP.',
    'err.no_project_dir': 'Chưa chọn thư mục dự án.',
    'err.busy': 'Đang chạy một lệnh khác.',
    'err.docker_start_failed': 'Không khởi động được {detail}.',
    'err.docker_app_not_found': 'Không tìm thấy Docker Desktop, OrbStack hay Rancher Desktop. Hãy khởi động Docker bằng tay.',
    'err.docker_manual': 'Hãy khởi động Docker bằng tay (systemctl start docker).',
    'manager.restart': 'Khởi động lại',
    'manager.port_busy_by': 'Cổng 8080 đang bị {owner} dùng',
    'services.title': 'Container',
    'services.logs': 'Log',
    'update.available': 'Có bản mới {version}.',
    'update.install': 'Cập nhật và khởi động lại',
    'update.installing': 'Đang tải bản cập nhật…',
    'autostart.label': 'Mở cùng máy (ẩn trên thanh menu)',
    'action.restart': 'Khởi động lại',
    'err.unknown_service': 'Không có container {detail} trong stack.',
    'err.update_failed': 'Cập nhật lỗi: {detail}',
    'services.start': 'Bật',
    'services.restart': 'Khởi động lại',
    'services.terminal': 'Terminal',
    'services.following': 'Đang theo dõi log {name}',
    'services.stop_follow': 'Dừng theo dõi',
    'tools.folder': 'Mở thư mục',
    'tools.code': 'VS Code',
    'tools.prune': 'Dọn dẹp Docker…',
    'action.start': 'Bật',
    'action.prune': 'Dọn dẹp Docker',
    'result.service_done': '{action} {name} xong.',
    'result.service_failed': '{action} {name} lỗi (mã {code}).',
    'err.terminal_failed': 'Không mở được Terminal.',
    'err.vscode_not_found': 'Không tìm thấy VS Code.',
    'err.source_in_stack': '{detail} nằm trong thư mục stack của app, hãy chọn thư mục khác.',
    'err.stop_stack_first': 'Hãy dừng stack đang chạy trước khi đổi chế độ.',
    'err.stack_install_failed': 'Không cài được Docker stack: {detail}',
  },
  en: {
    tagline: 'PHP environments running on Docker',
    'pick.title': 'Choose your source folder',
    'pick.desc': 'The folder with your PHP projects, one subfolder each (e.g. <code>~/code</code>). The Docker stack ships with the app.',
    'pick.button': 'Choose source folder…',
    'pick.repo': 'Already have a multi-php-docker checkout? Use it',
    'docker.download': 'Download Docker Desktop',
    'docker.start': 'Start Docker',
    'docker.missing': 'Docker is not installed',
    'docker.running': 'Running',
    'docker.starting': 'Starting… (may take ~30 seconds)',
    'docker.off': 'Stopped',
    'manager.start': 'Start',
    'manager.build': 'Rebuild',
    'manager.build_title': 'docker compose build, then start',
    'manager.ready': 'Ready',
    'manager.port_busy': 'Port 8080 is used by another program',
    'manager.building': 'Building images…',
    'manager.starting': 'Starting…',
    'manager.off': 'Not running',
    'manager.off_need_docker': 'Not running · start Docker first',
    open: 'Open Manager',
    'hint.start_manager': 'Start the Manager to open it.',
    'hint.start_docker': 'Start Docker, then the Manager.',
    'dir.label': 'Folder',
    'dir.change': 'Change',
    'dir.source': 'Source',
    'dir.use_repo': 'Use an existing checkout…',
    'dir.use_source': 'Use a source folder only…',
    'action.up': 'Start',
    'action.build': 'Build',
    'action.stop': 'Stop stack',
    'result.done': '{action} done.',
    'result.failed': '{action} failed (exit code {code}). Check the log above, fix it and try again.',
    'result.docker_off': 'Docker is stopped: start Docker first.',
    'result.already_running': 'The Manager is already running.',
    'err.not_project_dir': '{detail} has no Multi PHP docker-compose.yml.',
    'err.no_project_dir': 'No project folder chosen yet.',
    'err.busy': 'Another command is running.',
    'err.docker_start_failed': 'Could not start {detail}.',
    'err.docker_app_not_found': 'Docker Desktop, OrbStack or Rancher Desktop not found. Start Docker yourself.',
    'err.docker_manual': 'Start Docker yourself (systemctl start docker).',
    'manager.restart': 'Restart',
    'manager.port_busy_by': 'Port 8080 is used by {owner}',
    'services.title': 'Containers',
    'services.logs': 'Logs',
    'update.available': 'Version {version} is available.',
    'update.install': 'Update and restart',
    'update.installing': 'Downloading the update…',
    'autostart.label': 'Open at login (hidden in the menu bar)',
    'action.restart': 'Restart',
    'err.unknown_service': 'No {detail} container in the stack.',
    'err.update_failed': 'Update failed: {detail}',
    'services.start': 'Start',
    'services.restart': 'Restart',
    'services.terminal': 'Terminal',
    'services.following': 'Following logs of {name}',
    'services.stop_follow': 'Stop following',
    'tools.folder': 'Open folder',
    'tools.code': 'VS Code',
    'tools.prune': 'Clean up Docker…',
    'action.start': 'Start',
    'action.prune': 'Docker cleanup',
    'result.service_done': '{action} {name} done.',
    'result.service_failed': '{action} {name} failed (exit code {code}).',
    'err.terminal_failed': 'Could not open a terminal.',
    'err.vscode_not_found': 'VS Code not found.',
    'err.source_in_stack': '{detail} is inside the app’s stack folder; choose another folder.',
    'err.stop_stack_first': 'Stop the running stack before switching modes.',
    'err.stack_install_failed': 'Could not install the Docker stack: {detail}',
  },
}

const LANG_KEY = 'launcher-lang'
let lang = (() => {
  try {
    const saved = localStorage.getItem(LANG_KEY)
    if (saved in MESSAGES) return saved
  } catch (_) {}
  return (navigator.language || '').toLowerCase().startsWith('vi') ? 'vi' : 'en'
})()

function t(key, params = {}) {
  const text = MESSAGES[lang][key] ?? MESSAGES.vi[key] ?? key
  return text.replace(/\{(\w+)\}/g, (_, name) => params[name] ?? '')
}

function errorText(error) {
  const [key, detail = ''] = String(error).split(/\|(.*)/s)
  return MESSAGES[lang][`err.${key}`] ? t(`err.${key}`, { detail }) : String(error)
}

function applyStatic() {
  document.documentElement.lang = lang
  document.querySelectorAll('[data-i18n]').forEach((el) => (el.textContent = t(el.dataset.i18n)))
  document.querySelectorAll('[data-i18n-html]').forEach((el) => (el.innerHTML = t(el.dataset.i18nHtml)))
  document.querySelectorAll('[data-i18n-title]').forEach((el) => (el.title = t(el.dataset.i18nTitle)))
  document.querySelectorAll('[data-lang]').forEach((el) => el.setAttribute('aria-pressed', String(el.dataset.lang === lang)))
}

function setLang(next) {
  lang = next
  try {
    localStorage.setItem(LANG_KEY, next)
  } catch (_) {}
  applyStatic()
}
