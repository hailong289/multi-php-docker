/** Custom URL scheme registered by scripts/hosts/ensure_hosts_env.* */

export const HOSTS_PROTOCOL_SCHEME = 'multi-php-hosts'
export const HOSTS_PROTOCOL_ACTION = 'write'
export const HOSTS_PROTOCOL_WINDOW = 'multiPhpHostsWriter'

export function normalizeHostsWriteToken(token) {
  const value = String(token || '')
    .trim()
    .toLowerCase()
  if (!/^[a-f0-9]{8,64}$/.test(value)) return ''
  return value
}

export function newHostsWriteToken() {
  const bytes = new Uint8Array(8)
  globalThis.crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

export function hostsProtocolUrl(token = '') {
  const normalized = normalizeHostsWriteToken(token)
  const base = `${HOSTS_PROTOCOL_SCHEME}://${HOSTS_PROTOCOL_ACTION}`
  return normalized ? `${base}?id=${normalized}` : base
}

export function isHostsProtocolPlatform(userAgent = '', platform = '') {
  const ua = String(userAgent || '')
  const p = String(platform || '')
  const isWin = /Win/i.test(ua) || /Win/i.test(p)
  const isMac = /Mac/i.test(p) || /Mac OS/i.test(ua) || /Macintosh/i.test(ua)
  return isWin || isMac
}

/**
 * Open the OS protocol handler. Must run in the same turn as a user click.
 * Chromium blocks custom schemes after await and in hidden iframes.
 * Same-window navigation blanks the Manager UI, so open a named tab instead.
 */
export function launchHostsWriteProtocol(env = globalThis, token = '') {
  const nav = env.navigator || {}
  if (!isHostsProtocolPlatform(nav.userAgent, nav.platform)) return false
  const url = hostsProtocolUrl(token)
  try {
    const doc = env.document
    if (doc?.createElement && doc.body) {
      const a = doc.createElement('a')
      a.href = url
      a.target = HOSTS_PROTOCOL_WINDOW
      a.rel = 'noopener'
      doc.body.appendChild(a)
      a.click()
      a.remove()
      return true
    }
  } catch (_) {
    return false
  }
  return false
}
