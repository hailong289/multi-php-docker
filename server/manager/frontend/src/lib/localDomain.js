/** Hosts-only hostnames: name + .test | .local | custom suffix. */

export const LOCAL_TLDS = ['.test', '.local']
export const CUSTOM_TLD = 'custom'
export const DEFAULT_LOCAL_TLD = '.test'

export function normalizeCustomSuffix(suffix) {
  let value = String(suffix || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '')
  if (!value || value === '.') return ''
  if (!value.startsWith('.')) value = `.${value}`
  return value.replace(/^\.+/, '.').replace(/\.+$/, '')
}

export function resolveLocalSuffix(tld, customSuffix = '') {
  const value = String(tld || '')
    .trim()
    .toLowerCase()
  if (value === CUSTOM_TLD) {
    return normalizeCustomSuffix(customSuffix)
  }
  const withDot = value.startsWith('.') ? value : `.${value}`
  if (LOCAL_TLDS.includes(withDot)) return withDot
  return normalizeCustomSuffix(withDot)
}

export function parseLocalDomain(domain) {
  const value = String(domain || '')
    .trim()
    .toLowerCase()
  for (const tld of LOCAL_TLDS) {
    if (value.endsWith(tld) && value.length > tld.length) {
      return { name: value.slice(0, -tld.length).replace(/\.+$/, ''), tld, custom: '' }
    }
  }
  const lastDot = value.lastIndexOf('.')
  if (lastDot > 0) {
    return {
      name: value.slice(0, lastDot),
      tld: CUSTOM_TLD,
      custom: value.slice(lastDot),
    }
  }
  return { name: value.replace(/\.+$/, ''), tld: DEFAULT_LOCAL_TLD, custom: '' }
}

export function composeLocalDomain(name, tld = DEFAULT_LOCAL_TLD, customSuffix = '') {
  let label = String(name || '')
    .trim()
    .toLowerCase()
  for (const suffix of LOCAL_TLDS) {
    if (label.endsWith(suffix)) {
      label = label.slice(0, -suffix.length)
    }
  }
  label = label.replace(/^\.+|\.+$/g, '').replace(/\s+/g, '')
  const suffix = resolveLocalSuffix(tld, customSuffix)
  if (suffix && label.endsWith(suffix)) {
    label = label.slice(0, -suffix.length).replace(/\.+$/, '')
  }
  if (!label || !suffix) return ''
  return `${label}${suffix}`
}
