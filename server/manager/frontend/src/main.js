import { createApp } from 'vue'
import { createI18n } from 'vue-i18n'
import App from './App.vue'
import router from './router'
import { en } from './i18n/en'
import { vi } from './i18n/vi'
import './styles.css'

function initialLocale() {
  try {
    const saved = localStorage.getItem('manager-locale')
    if (saved === 'en' || saved === 'vi') return saved
  } catch (_) {}
  return navigator.language?.toLowerCase().startsWith('vi') ? 'vi' : 'en'
}

const i18n = createI18n({
  legacy: false,
  locale: initialLocale(),
  fallbackLocale: 'en',
  messages: { en, vi },
})

createApp(App).use(i18n).use(router).mount('#app')
