import { createApp } from 'vue'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import App from './App.vue'
import router from './router'
import { en } from './i18n/en'
import { vi } from './i18n/vi'
import 'primeicons/primeicons.css'
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

const app = createApp(App)
app.use(i18n)
app.use(router)
app.use(PrimeVue, {
  theme: {
    preset: Aura,
    options: {
      darkModeSelector: '[data-theme="dark"]',
      cssLayer: false,
    },
  },
})
app.use(ToastService)
app.use(ConfirmationService)
app.mount('#app')
