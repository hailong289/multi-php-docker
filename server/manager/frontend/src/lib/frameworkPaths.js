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

const KNOWN_DOC_ROOTS = ['webroot', 'public', 'web']

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
 * @param {string} sourcePrefix e.g. /var/www/source_php8.5
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

/** Project directory only (no document-root suffix). */
export function buildProjectDir(sourcePrefix, appName) {
  const prefix = String(sourcePrefix || '').replace(/\/+$/, '')
  return `${prefix}/${appFolderName(appName)}`
}

/** Relative document-root folder for a framework (`public`, `web`, or ``). */
export function buildDocRoot(frameworkId) {
  const preset = frameworkById(frameworkId)
  if (preset.suffix === null) return ''
  return String(preset.suffix).replace(/^\/+/, '')
}

export function joinServerPath(projectDir, docRoot) {
  const base = String(projectDir || '').replace(/\/+$/, '')
  const root = String(docRoot || '')
    .trim()
    .replace(/^\/+|\/+$/g, '')
  if (!base) return root ? `/${root}` : ''
  return root ? `${base}/${root}` : base
}

/**
 * Split SERVER_PATH into project directory + relative document root.
 * @param {string} serverPath
 */
export function splitServerPath(serverPath) {
  const path = String(serverPath || '').replace(/\/+$/, '')
  if (!path) return { projectDir: '', docRoot: '' }
  for (const root of KNOWN_DOC_ROOTS) {
    if (path.endsWith(`/${root}`)) {
      return {
        projectDir: path.slice(0, -(root.length + 1)),
        docRoot: root,
      }
    }
  }
  return { projectDir: path, docRoot: '' }
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
  const parts = rel.replace(/^\/+/, '').split('/').filter(Boolean)
  if (parts.length === 1) return 'plain'
  return 'custom'
}

/** Host-relative hint under the repo: source_php8.5/my-app/public */
export function hostRelativeHint(sourcePrefix, appName, frameworkId) {
  const containerPath = buildServerPath(sourcePrefix, appName, frameworkId)
  if (!containerPath) return ''
  return containerPath.replace(/^\/var\/www\//, '')
}
