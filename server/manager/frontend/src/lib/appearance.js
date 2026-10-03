import { updatePrimaryPalette, updateSurfacePalette } from '@primevue/themes'

export const THEME_MODES = ['system', 'light', 'dark']

/** Match PrimeVue configurator primary swatches (order + colors). */
export const PRIMARY_COLORS = [
  { id: 'noir', token: 'zinc', swatch: '#ffffff' },
  { id: 'emerald', token: 'emerald', swatch: '#10B981' },
  { id: 'green', token: 'green', swatch: '#22C55E' },
  { id: 'lime', token: 'lime', swatch: '#84CC16' },
  { id: 'orange', token: 'orange', swatch: '#F97316' },
  { id: 'amber', token: 'amber', swatch: '#F59E0B' },
  { id: 'yellow', token: 'yellow', swatch: '#EAB308' },
  { id: 'teal', token: 'teal', swatch: '#14B8A6' },
  { id: 'cyan', token: 'cyan', swatch: '#06B6D4' },
  { id: 'sky', token: 'sky', swatch: '#0EA5E9' },
  { id: 'blue', token: 'blue', swatch: '#3B82F6' },
  { id: 'indigo', token: 'indigo', swatch: '#6366F1' },
  { id: 'violet', token: 'violet', swatch: '#8B5CF6' },
  { id: 'purple', token: 'purple', swatch: '#A855F7' },
  { id: 'fuchsia', token: 'fuchsia', swatch: '#D946EF' },
  { id: 'pink', token: 'pink', swatch: '#EC4899' },
  { id: 'rose', token: 'rose', swatch: '#F43F5E' },
]

/**
 * Surface palettes with concrete hex (PrimeVue configurator + a few extras).
 * Each preset must have its own scale so switching is visible.
 */
export const SURFACE_COLORS = [
  {
    id: 'slate',
    swatch: '#64748B',
    palette: {
      0: '#ffffff',
      50: '#f8fafc',
      100: '#f1f5f9',
      200: '#e2e8f0',
      300: '#cbd5e1',
      400: '#94a3b8',
      500: '#64748b',
      600: '#475569',
      700: '#334155',
      800: '#1e293b',
      900: '#0f172a',
      950: '#020617',
    },
  },
  {
    id: 'gray',
    swatch: '#6B7280',
    palette: {
      0: '#ffffff',
      50: '#f9fafb',
      100: '#f3f4f6',
      200: '#e5e7eb',
      300: '#d1d5db',
      400: '#9ca3af',
      500: '#6b7280',
      600: '#4b5563',
      700: '#374151',
      800: '#1f2937',
      900: '#111827',
      950: '#030712',
    },
  },
  {
    id: 'zinc',
    swatch: '#71717A',
    palette: {
      0: '#ffffff',
      50: '#fafafa',
      100: '#f4f4f5',
      200: '#e4e4e7',
      300: '#d4d4d8',
      400: '#a1a1aa',
      500: '#71717a',
      600: '#52525b',
      700: '#3f3f46',
      800: '#27272a',
      900: '#18181b',
      950: '#09090b',
    },
  },
  {
    id: 'neutral',
    swatch: '#737373',
    palette: {
      0: '#ffffff',
      50: '#fafafa',
      100: '#f5f5f5',
      200: '#e5e5e5',
      300: '#d4d4d4',
      400: '#a3a3a3',
      500: '#737373',
      600: '#525252',
      700: '#404040',
      800: '#262626',
      900: '#171717',
      950: '#0a0a0a',
    },
  },
  {
    id: 'stone',
    swatch: '#78716C',
    palette: {
      0: '#ffffff',
      50: '#fafaf9',
      100: '#f5f5f4',
      200: '#e7e5e4',
      300: '#d6d3d1',
      400: '#a8a29e',
      500: '#78716c',
      600: '#57534e',
      700: '#44403c',
      800: '#292524',
      900: '#1c1917',
      950: '#0c0a09',
    },
  },
  {
    id: 'soho',
    swatch: '#8E8F93',
    palette: {
      0: '#ffffff',
      50: '#f4f4f4',
      100: '#e8e9e9',
      200: '#d2d2d4',
      300: '#bbbcbe',
      400: '#a5a5a9',
      500: '#8e8f93',
      600: '#77787d',
      700: '#616268',
      800: '#4a4b52',
      900: '#34343d',
      950: '#1d1e27',
    },
  },
  {
    id: 'viva',
    swatch: '#87898A',
    palette: {
      0: '#ffffff',
      50: '#f3f3f3',
      100: '#e7e7e8',
      200: '#cfd0d0',
      300: '#b7b8b9',
      400: '#9fa1a1',
      500: '#87898a',
      600: '#6e7173',
      700: '#565a5b',
      800: '#3e4244',
      900: '#262b2c',
      950: '#0e1315',
    },
  },
  {
    id: 'ocean',
    swatch: '#828787',
    palette: {
      0: '#ffffff',
      50: '#fbfcfc',
      100: '#F7F9F8',
      200: '#EFF3F2',
      300: '#DADEDD',
      400: '#B1B7B6',
      500: '#828787',
      600: '#5F7274',
      700: '#415B61',
      800: '#29444E',
      900: '#183240',
      950: '#0c1920',
    },
  },
  {
    id: 'mist',
    swatch: '#9CA3AF',
    palette: {
      0: '#ffffff',
      50: '#f7f9fc',
      100: '#eef2f7',
      200: '#dce4ef',
      300: '#c2cddc',
      400: '#9ca3af',
      500: '#7b8798',
      600: '#5f6b7a',
      700: '#4a5564',
      800: '#343e4c',
      900: '#212833',
      950: '#12161e',
    },
  },
  {
    id: 'noir',
    swatch: '#27272A',
    palette: {
      0: '#ffffff',
      50: '#f6f6f7',
      100: '#ececed',
      200: '#d4d4d8',
      300: '#a1a1aa',
      400: '#71717a',
      500: '#52525b',
      600: '#3f3f46',
      700: '#27272a',
      800: '#18181b',
      900: '#0f0f12',
      950: '#050506',
    },
  },
  {
    id: 'ember',
    swatch: '#A8A29E',
    palette: {
      0: '#ffffff',
      50: '#faf7f5',
      100: '#f3ebe6',
      200: '#e6d5cb',
      300: '#d2b8a8',
      400: '#b89a88',
      500: '#a8a29e',
      600: '#8a7468',
      700: '#6f5a50',
      800: '#4d3e37',
      900: '#322822',
      950: '#1c1613',
    },
  },
  {
    id: 'renegade',
    swatch: '#57534E',
    palette: {
      0: '#ffffff',
      50: '#f8f6f3',
      100: '#efebe4',
      200: '#ddd4c7',
      300: '#c4b6a3',
      400: '#9f8f7a',
      500: '#7a6d5c',
      600: '#57534e',
      700: '#46423d',
      800: '#322f2b',
      900: '#211f1c',
      950: '#121110',
    },
  },
]

const THEME_KEY = 'manager-theme'
const PRIMARY_KEY = 'manager-primary'
const SURFACE_KEY = 'manager-surface'

const SCALE = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]

function paletteFromToken(token) {
  const out = {}
  for (const step of SCALE) out[step] = `{${token}.${step}}`
  return out
}

function findSurface(id) {
  return SURFACE_COLORS.find((item) => item.id === id) || SURFACE_COLORS[0]
}

export function readStoredThemeMode() {
  try {
    const saved = localStorage.getItem(THEME_KEY)
    if (THEME_MODES.includes(saved)) return saved
  } catch (_) {}
  return 'system'
}

export function readStoredPrimaryId() {
  try {
    const saved = localStorage.getItem(PRIMARY_KEY)
    if (PRIMARY_COLORS.some((item) => item.id === saved)) return saved
  } catch (_) {}
  return 'blue'
}

export function readStoredSurfaceId() {
  try {
    const saved = localStorage.getItem(SURFACE_KEY)
    if (SURFACE_COLORS.some((item) => item.id === saved)) return saved
  } catch (_) {}
  return 'slate'
}

export function resolveTheme(mode = readStoredThemeMode()) {
  if (mode === 'system') {
    return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  }
  return mode === 'light' ? 'light' : 'dark'
}

export function applyThemeMode(mode) {
  const next = THEME_MODES.includes(mode) ? mode : 'system'
  try {
    localStorage.setItem(THEME_KEY, next)
  } catch (_) {}
  const effective = resolveTheme(next)
  document.documentElement.dataset.theme = effective
  document.documentElement.dataset.themeMode = next
  syncLegacyPrimaryVars(readStoredPrimaryId(), effective)
  syncLegacySurfaceVars(readStoredSurfaceId(), effective)
  return next
}

export function applyPrimaryColor(id) {
  const preset = PRIMARY_COLORS.find((item) => item.id === id) || PRIMARY_COLORS.find((i) => i.id === 'blue')
  try {
    localStorage.setItem(PRIMARY_KEY, preset.id)
  } catch (_) {}
  updatePrimaryPalette(paletteFromToken(preset.token))
  document.documentElement.dataset.primary = preset.id
  syncLegacyPrimaryVars(preset.id, resolveTheme())
  return preset.id
}

export function applySurfaceColor(id) {
  const preset = findSurface(id)
  try {
    localStorage.setItem(SURFACE_KEY, preset.id)
  } catch (_) {}
  updateSurfacePalette(preset.palette)
  document.documentElement.dataset.surface = preset.id
  syncLegacySurfaceVars(preset.id, resolveTheme())
  return preset.id
}

function setCssVar(name, value) {
  if (value) document.documentElement.style.setProperty(name, value)
}

/** Darken a hex color toward black by amount (0–1). */
function colorMixBlack(hex, amount) {
  if (!hex?.startsWith('#') || hex.length < 7) return hex
  const n = parseInt(hex.slice(1), 16)
  let r = (n >> 16) & 255
  let g = (n >> 8) & 255
  let b = n & 255
  const t = 1 - Math.min(1, Math.max(0, amount))
  r = Math.round(r * t)
  g = Math.round(g * t)
  b = Math.round(b * t)
  return `#${((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)}`
}

/** Map chosen surface onto shell CSS vars (--bg, --panel, …). */
function syncLegacySurfaceVars(id, theme = resolveTheme()) {
  const p = findSurface(id).palette

  if (theme === 'light') {
    setCssVar('--bg', p[100])
    setCssVar('--bg-accent', p[200])
    setCssVar('--panel', p[0])
    setCssVar('--panel-strong', p[50])
    setCssVar('--input', p[0])
    setCssVar('--command', p[100])
    setCssVar('--command-text', p[800])
    setCssVar('--line', p[200])
    setCssVar('--input-line', p[300])
    setCssVar('--text', p[900])
    setCssVar('--muted', p[500])
    setCssVar('--label', p[700])
    setCssVar('--button-bg', p[50])
    setCssVar('--button-line', p[300])
    setCssVar('--badge-bg', p[100])
    setCssVar('--badge-line', p[300])
    setCssVar('--badge-text', p[700])
    setCssVar('--shadow', 'rgba(33, 54, 84, 0.12)')
    // Log panes stay terminal-dark for contrast; tint from surface.
    setCssVar('--log-bg', p[900])
    setCssVar('--log-fg', p[100])
    setCssVar('--log-muted', p[400])
  } else {
    setCssVar('--bg', p[950])
    setCssVar('--bg-accent', p[800])
    setCssVar('--panel', p[900])
    setCssVar('--panel-strong', p[950])
    setCssVar('--input', p[950])
    setCssVar('--command', p[950])
    setCssVar('--command-text', p[200])
    setCssVar('--line', p[700])
    setCssVar('--input-line', p[600])
    setCssVar('--text', p[50])
    setCssVar('--muted', p[400])
    setCssVar('--label', p[200])
    setCssVar('--button-bg', p[800])
    setCssVar('--button-line', p[600])
    setCssVar('--badge-bg', p[800])
    setCssVar('--badge-line', p[600])
    setCssVar('--badge-text', p[200])
    setCssVar('--shadow', 'rgba(0, 0, 0, 0.22)')
    setCssVar('--log-bg', colorMixBlack(p[950], 0.18))
    setCssVar('--log-fg', p[100])
    setCssVar('--log-muted', p[400])
  }
}

function syncLegacyPrimaryVars(id, theme = resolveTheme()) {
  const preset = PRIMARY_COLORS.find((item) => item.id === id) || PRIMARY_COLORS.find((i) => i.id === 'blue')
  let color = preset.swatch
  if (preset.id === 'noir') color = theme === 'light' ? '#18181B' : '#A1A1AA'
  else if (theme === 'light') color = shade(preset.swatch, -0.08)
  setCssVar('--primary', color)
  setCssVar('--primary-line', color)
  setCssVar('--blue', color)
}

function shade(hex, amount) {
  if (!hex?.startsWith('#') || hex.length < 7) return hex
  const n = parseInt(hex.slice(1), 16)
  let r = (n >> 16) & 255
  let g = (n >> 8) & 255
  let b = n & 255
  r = Math.min(255, Math.max(0, Math.round(r + 255 * amount)))
  g = Math.min(255, Math.max(0, Math.round(g + 255 * amount)))
  b = Math.min(255, Math.max(0, Math.round(b + 255 * amount)))
  return `#${((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)}`
}

/** Call after PrimeVue is installed so palette updates apply. */
export function bootstrapAppearance() {
  applyThemeMode(readStoredThemeMode())
  applyPrimaryColor(readStoredPrimaryId())
  applySurfaceColor(readStoredSurfaceId())
}
