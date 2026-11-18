/** Quick commands for the site terminal: presets + the viewer's own saved ones. */
export const TERMINAL_COMMANDS_STORAGE_KEY = 'manager-terminal-commands'

export const PRESET_TERMINAL_COMMANDS = [
  'composer install',
  'composer update',
  'composer dump-autoload',
  'php artisan migrate',
  'php artisan optimize:clear',
  'php artisan route:list',
  'php artisan tinker',
  'php artisan queue:work',
  'git status',
  'git pull',
]

export function loadCustomTerminalCommands() {
  try {
    const raw = JSON.parse(localStorage.getItem(TERMINAL_COMMANDS_STORAGE_KEY) || '[]')
    return Array.isArray(raw) ? raw.filter((cmd) => typeof cmd === 'string' && cmd.trim()) : []
  } catch (_) {
    return []
  }
}

export function saveCustomTerminalCommands(list) {
  try {
    localStorage.setItem(TERMINAL_COMMANDS_STORAGE_KEY, JSON.stringify(list))
  } catch (_) {}
}
