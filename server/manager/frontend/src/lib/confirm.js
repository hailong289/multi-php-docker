import ConfirmationEventBus from 'primevue/confirmationeventbus'

/**
 * Promise-based confirm using PrimeVue ConfirmationService event bus.
 * Works outside setup() (e.g. useManager helpers).
 */
export function confirmDialog(message, options = {}) {
  const {
    header = '',
    icon = 'pi pi-exclamation-triangle',
    acceptLabel = 'OK',
    rejectLabel = 'Cancel',
    acceptSeverity = 'primary',
    rejectSeverity = 'secondary',
  } = options

  return new Promise((resolve) => {
    ConfirmationEventBus.emit('confirm', {
      message,
      header: header || undefined,
      icon,
      rejectProps: {
        label: rejectLabel,
        severity: rejectSeverity,
        outlined: true,
      },
      acceptProps: {
        label: acceptLabel,
        severity: acceptSeverity,
      },
      accept: () => resolve(true),
      reject: () => resolve(false),
    })
  })
}
