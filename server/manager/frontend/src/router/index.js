import { createRouter, createWebHistory } from 'vue-router'
import DomainsView from '../views/DomainsView.vue'
import HomeView from '../views/HomeView.vue'
import PhpVersionsView from '../views/PhpVersionsView.vue'
import PhpVersionDetailView from '../views/PhpVersionDetailView.vue'
import NginxView from '../views/NginxView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
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
      path: '/php-versions',
      name: 'php-versions',
      component: PhpVersionsView,
      meta: { titleKey: 'nav.php_versions' },
    },
    {
      path: '/php-versions/:service',
      name: 'php-version-detail',
      component: PhpVersionDetailView,
      meta: { titleKey: 'nav.php_versions' },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

export default router
