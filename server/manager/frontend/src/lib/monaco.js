import editorWorker from 'monaco-editor/esm/vs/editor/editor.worker?worker'
import 'monaco-editor/min/vs/editor/editor.main.css'

let configured = false
let themesRegistered = false

export function ensureMonacoEnvironment() {
  if (configured || typeof self === 'undefined') return
  self.MonacoEnvironment = {
    getWorker() {
      return new editorWorker()
    },
  }
  configured = true
}

export function monacoThemeFromDocument() {
  return document.documentElement.getAttribute('data-theme') === 'light'
    ? 'manager-light'
    : 'manager-dark'
}

/** Register Aura-aligned Monaco themes once monaco module is loaded. */
export function registerManagerMonacoThemes(monaco) {
  if (!monaco || themesRegistered) return
  monaco.editor.defineTheme('manager-dark', {
    base: 'vs-dark',
    inherit: true,
    rules: [],
    colors: {
      'editor.background': '#0b1220',
      'editor.foreground': '#e8eef8',
      'editorLineNumber.foreground': '#6b7c93',
      'editorLineNumber.activeForeground': '#9dafc8',
      'editor.selectionBackground': '#1d4ed866',
      'editor.lineHighlightBackground': '#142033',
      'editorCursor.foreground': '#58a6ff',
      'editorWidget.background': '#111c2e',
      'editorWidget.border': '#263752',
      'editorIndentGuide.background': '#263752',
      'editorIndentGuide.activeBackground': '#365273',
      'scrollbarSlider.background': '#30425f66',
      'scrollbarSlider.hoverBackground': '#36527399',
    },
  })
  monaco.editor.defineTheme('manager-light', {
    base: 'vs',
    inherit: true,
    rules: [],
    colors: {
      'editor.background': '#ffffff',
      'editor.foreground': '#172033',
      'editorLineNumber.foreground': '#8a9bb0',
      'editorLineNumber.activeForeground': '#5d6e85',
      'editor.selectionBackground': '#0969da33',
      'editor.lineHighlightBackground': '#f2f6fc',
      'editorCursor.foreground': '#0969da',
      'editorWidget.background': '#ffffff',
      'editorWidget.border': '#d5dfec',
      'editorIndentGuide.background': '#d5dfec',
      'editorIndentGuide.activeBackground': '#b7c5d8',
      'scrollbarSlider.background': '#b7c5d866',
      'scrollbarSlider.hoverBackground': '#9dafc899',
    },
  })
  themesRegistered = true
}

export function terminalThemeFromDocument() {
  const styles = getComputedStyle(document.documentElement)
  const bg = (styles.getPropertyValue('--log-bg') || '').trim() || '#0f172a'
  const fg = (styles.getPropertyValue('--log-fg') || '').trim() || '#e2e8f0'
  const muted = (styles.getPropertyValue('--log-muted') || '').trim() || '#94a3b8'
  const primary =
    (styles.getPropertyValue('--p-primary-color') || '').trim() ||
    (styles.getPropertyValue('--primary') || '').trim() ||
    '#3b82f6'

  return {
    background: bg,
    foreground: fg,
    cursor: primary,
    cursorAccent: bg,
    selectionBackground: `${primary}66`,
    black: bg,
    red: '#f87171',
    green: '#4ade80',
    yellow: '#fbbf24',
    blue: '#60a5fa',
    magenta: '#c084fc',
    cyan: '#22d3ee',
    white: fg,
    brightBlack: muted,
    brightRed: '#fca5a5',
    brightGreen: '#86efac',
    brightYellow: '#fde68a',
    brightBlue: '#93c5fd',
    brightMagenta: '#d8b4fe',
    brightCyan: '#67e8f9',
    brightWhite: '#ffffff',
  }
}
