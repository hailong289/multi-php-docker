const RECORD_RE = /^\[?\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/
const LEVEL_RE =
  /\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG)\b|\[(EMERGENCY|ALERT|CRITICAL|ERROR|WARN|WARNING|NOTICE|INFO|DEBUG)\]|^(?:\[.*?\]\s*)?(EMERGENCY|ALERT|CRITICAL|ERROR|WARN|WARNING|NOTICE|INFO|DEBUG)\b/i

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function normalizeLevel(word) {
  const level = String(word || '').toLowerCase()
  if (level === 'emergency' || level === 'alert' || level === 'critical' || level === 'error') {
    return 'error'
  }
  if (level === 'warning' || level === 'warn') return 'warning'
  if (level === 'notice' || level === 'info') return 'info'
  if (level === 'debug') return 'debug'
  return ''
}

function levelOf(line) {
  const match = line.slice(0, 160).match(LEVEL_RE)
  if (!match) return ''
  return normalizeLevel(match[1] || match[2] || match[3])
}

/**
 * Escape log text and wrap each line. Stack lines keep the level of the
 * record they belong to until the next timestamped line.
 * @param {string} content
 */
export function highlightLog(content) {
  const lines = String(content || '').split(/\r\n|\n|\r/)
  let current = ''
  return lines
    .map((line) => {
      if (RECORD_RE.test(line) || levelOf(line)) {
        current = levelOf(line)
      }
      const kind = current ? ` log-line-${current}` : ''
      return `<span class="log-line${kind}">${escapeHtml(line) || ' '}</span>`
    })
    .join('')
}
