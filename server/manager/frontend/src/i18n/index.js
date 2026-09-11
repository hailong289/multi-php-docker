import { createI18n } from 'vue-i18n'
import { en } from './en'
import { vi } from './vi'

function initialLocale() {
  try {
    const saved = localStorage.getItem('manager-locale')
    if (saved === 'en' || saved === 'vi') return saved
  } catch (_) {}
  return navigator.language?.toLowerCase().startsWith('vi') ? 'vi' : 'en'
}

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale(),
  fallbackLocale: 'en',
  messages: { en, vi },
})

export function t(key, values) {
  return i18n.global.t(key, values)
}
