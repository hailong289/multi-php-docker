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
  const dark = document.documentElement.getAttribute('data-theme') !== 'light'
  if (dark) {
    return {
      background: '#0b1220',
      foreground: '#e8eef8',
      cursor: '#58a6ff',
      cursorAccent: '#0b1220',
      selectionBackground: '#1d4ed866',
      black: '#0b1220',
      red: '#ff7b86',
      green: '#3fb950',
      yellow: '#d29922',
      blue: '#58a6ff',
      magenta: '#bc8cff',
      cyan: '#39c5cf',
      white: '#e8eef8',
      brightBlack: '#6b7c93',
      brightRed: '#ffa198',
      brightGreen: '#56d364',
      brightYellow: '#e3b341',
      brightBlue: '#79c0ff',
      brightMagenta: '#d2a8ff',
      brightCyan: '#56d4dd',
      brightWhite: '#ffffff',
    }
  }
  return {
    background: '#ffffff',
    foreground: '#172033',
    cursor: '#0969da',
    cursorAccent: '#ffffff',
    selectionBackground: '#0969da33',
    black: '#172033',
    red: '#cf222e',
    green: '#1a7f37',
    yellow: '#9a6700',
    blue: '#0969da',
    magenta: '#8250df',
    cyan: '#1b7c83',
    white: '#5d6e85',
    brightBlack: '#8a9bb0',
    brightRed: '#a40e26',
    brightGreen: '#116329',
    brightYellow: '#7d4e00',
    brightBlue: '#0550ae',
    brightMagenta: '#6639ba',
    brightCyan: '#1b7c83',
    brightWhite: '#172033',
  }
}
