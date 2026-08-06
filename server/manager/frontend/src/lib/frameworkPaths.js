/** Document-root presets for common PHP frameworks. */

/** @typedef {{ id: string, suffix: string | null }} FrameworkPreset */

/** @type {FrameworkPreset[]} */
export const FRAMEWORK_PRESETS = [
  { id: 'laravel', suffix: '/public' },
  { id: 'symfony', suffix: '/public' },
  { id: 'wordpress', suffix: '' },
  { id: 'codeigniter', suffix: '/public' },
  { id: 'yii', suffix: '/web' },
  { id: 'cakephp', suffix: '/webroot' },
  { id: 'slim', suffix: '/public' },
  { id: 'drupal', suffix: '/web' },
  { id: 'plain', suffix: '' },
  { id: 'custom', suffix: null },
]

export function frameworkById(id) {
  return FRAMEWORK_PRESETS.find((item) => item.id === id) || FRAMEWORK_PRESETS[FRAMEWORK_PRESETS.length - 1]
}

/** App folder name from form app_name (fallback slug). */
export function appFolderName(appName, fallback = 'my-app') {
  const raw = String(appName || '').trim()
  if (!raw) return fallback
  const cleaned = raw
    .replace(/[^a-zA-Z0-9._-]+/g, '-')
    .replace(/^-+|-+$/g, '')
  return cleaned || fallback
}

/**
 * @param {string} sourcePrefix e.g. /var/www/source_php8.2
 * @param {string} appName
 * @param {string} frameworkId
 */
export function buildServerPath(sourcePrefix, appName, frameworkId) {
  const preset = frameworkById(frameworkId)
  if (preset.suffix === null) return null
  const prefix = String(sourcePrefix || '').replace(/\/+$/, '')
  const app = appFolderName(appName)
  return `${prefix}/${app}${preset.suffix}`
}

/**
 * Guess framework from an existing SERVER_PATH.
 * @param {string} serverPath
 * @param {string} sourcePrefix
 */
export function detectFramework(serverPath, sourcePrefix) {
  const path = String(serverPath || '').replace(/\/+$/, '')
  if (!path) return 'laravel'
  const prefix = String(sourcePrefix || '').replace(/\/+$/, '')
  let rel = path
  if (prefix && path.startsWith(prefix + '/')) {
    rel = path.slice(prefix.length)
  }
  if (rel.endsWith('/webroot')) return 'cakephp'
  if (rel.endsWith('/web')) return 'yii'
  if (rel.endsWith('/public')) return 'laravel'
  // single-segment project root: /app
  const parts = rel.replace(/^\/+/, '').split('/').filter(Boolean)
  if (parts.length === 1) return 'plain'
  return 'custom'
}

/** Host-relative hint under the repo: source_php8.2/my-app/public */
export function hostRelativeHint(sourcePrefix, appName, frameworkId) {
  const containerPath = buildServerPath(sourcePrefix, appName, frameworkId)
  if (!containerPath) return ''
  return containerPath.replace(/^\/var\/www\//, '')
}
