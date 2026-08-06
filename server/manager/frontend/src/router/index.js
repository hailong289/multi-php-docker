import { createRouter, createWebHistory } from 'vue-router'
import DomainsView from '../views/DomainsView.vue'
import HomeView from '../views/HomeView.vue'
import LoginView from '../views/LoginView.vue'
import PhpVersionsView from '../views/PhpVersionsView.vue'
import PhpVersionCatalogView from '../views/PhpVersionCatalogView.vue'
import PhpVersionDetailView from '../views/PhpVersionDetailView.vue'
import NginxView from '../views/NginxView.vue'
import ServicesView from '../views/ServicesView.vue'
import SupervisorView from '../views/SupervisorView.vue'
import { apiGet, setCsrfToken } from '../api'
import { applySessionPayload, authState } from '../lib/authState'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { public: true, titleKey: 'login.title' },
    },
    {
      path: '/',
      name: 'home',
      component: HomeView,
      meta: { titleKey: 'nav.home' },
    },
    {
      path: '/domains',
      name: 'domains',
      component: DomainsView,
      meta: { titleKey: 'nav.domains' },
    },
    {
      path: '/nginx',
      name: 'nginx',
      component: NginxView,
      meta: { titleKey: 'nav.nginx' },
    },
    {
      path: '/services',
      name: 'services',
      component: ServicesView,
      meta: { titleKey: 'nav.services' },
    },
    {
      path: '/php-versions',
      name: 'php-versions',
      component: PhpVersionsView,
      meta: { titleKey: 'nav.php_versions' },
    },
    {
      path: '/php-versions/catalog',
      name: 'php-version-catalog',
      component: PhpVersionCatalogView,
      meta: { titleKey: 'nav.php_catalog' },
    },
    {
      path: '/php-versions/:service/supervisor',
      name: 'php-version-supervisor',
      component: SupervisorView,
      meta: { titleKey: 'supervisor.title' },
    },
    {
      path: '/php-versions/:service',
      name: 'php-version-detail',
      component: PhpVersionDetailView,
      meta: { titleKey: 'nav.php_versions' },
    },
    {
      path: '/supervisor',
      redirect: '/php-versions',
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

async function ensureSession() {
  if (authState.ready) return
  const session = await apiGet('/api/session')
  if (session.csrf_token) setCsrfToken(session.csrf_token)
  applySessionPayload(session)
}

router.beforeEach(async (to) => {
  try {
    await ensureSession()
  } catch (_) {
    applySessionPayload({
      remote: false,
      authenticated: true,
      locked: false,
      domain: '',
    })
  }

  if (authState.remote && (!authState.authenticated || authState.locked) && !to.meta.public) {
    return { name: 'login' }
  }
  if (authState.remote && authState.authenticated && !authState.locked && to.name === 'login') {
    return { name: 'home' }
  }
  return true
})

export default router
