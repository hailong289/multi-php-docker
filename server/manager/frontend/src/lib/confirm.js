import ConfirmationEventBus from 'primevue/confirmationeventbus'
import { t } from '../i18n'

/**
 * Promise-based confirm using PrimeVue ConfirmationService event bus.
 * Works outside setup() (e.g. useManager helpers).
 */
export function confirmDialog(message, options = {}) {
  const {
    header,
    icon,
    acceptLabel = t('action.ok'),
    rejectLabel = t('action.cancel'),
    acceptSeverity = 'primary',
    rejectSeverity = 'secondary',
  } = options

  const isDanger = acceptSeverity === 'danger'
  const resolvedHeader =
    header || (isDanger ? t('confirm.title_danger') : t('confirm.title'))
  const resolvedIcon =
    icon || (isDanger ? 'pi pi-trash' : 'pi pi-exclamation-triangle')

  return new Promise((resolve) => {
    let settled = false
    const finish = (value) => {
      if (settled) return
      settled = true
      resolve(value)
    }

    ConfirmationEventBus.emit('confirm', {
      message,
      header: resolvedHeader,
      icon: resolvedIcon,
      closable: true,
      dismissableMask: false,
      blockScroll: true,
      rejectProps: {
        label: rejectLabel,
        severity: rejectSeverity,
        outlined: true,
      },
      acceptProps: {
        label: acceptLabel,
        severity: acceptSeverity,
      },
      accept: () => finish(true),
      reject: () => finish(false),
      onHide: () => finish(false),
    })
  })
}
