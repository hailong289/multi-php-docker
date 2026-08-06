import editorWorker from 'monaco-editor/esm/vs/editor/editor.worker?worker'
import 'monaco-editor/min/vs/editor/editor.main.css'

let configured = false

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
  return document.documentElement.getAttribute('data-theme') === 'light' ? 'vs' : 'vs-dark'
}
