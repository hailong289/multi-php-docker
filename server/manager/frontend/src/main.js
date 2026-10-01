import { createApp } from 'vue'
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import App from './App.vue'
import router from './router'
import { i18n } from './i18n'
import { bootstrapAppearance } from './lib/appearance'
import 'primeicons/primeicons.css'
import './styles.css'

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
bootstrapAppearance()
app.mount('#app')
