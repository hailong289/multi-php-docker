import ToastEventBus from 'primevue/toasteventbus'

const SEVERITY_MAP = {
  success: 'success',
  failure: 'error',
  error: 'error',
  warn: 'warn',
  warning: 'warn',
  info: 'info',
}

/**
 * Bridge for showToast() outside Vue setup() — uses the same event bus as ToastService.
 */
export function addToast({ severity = 'info', summary = '', detail = '', life = 4200 } = {}) {
  if (!summary && !detail) return
  ToastEventBus.emit('add', { severity, summary, detail, life })
}

export function toastSeverityFromType(type) {
  return SEVERITY_MAP[type] || 'info'
}
