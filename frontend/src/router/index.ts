import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'

declare global {
  interface Window {
    ym?: (counterId: number, method: string, ...args: unknown[]) => void
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'home', component: HomeView },
    { path: '/contact', redirect: '/app/contact' },
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/AuthLogin.vue'),
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('../views/AuthRegister.vue'),
    },
    {
      path: '/auth/callback',
      name: 'auth-callback',
      component: () => import('../views/AuthCallback.vue'),
    },
    {
      path: '/book/:slug',
      name: 'public-book',
      component: () => import('../views/PublicBookView.vue'),
    },
    {
      path: '/app',
      component: () => import('../views/DashboardLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: '/app/profile' },
        { path: 'profile', name: 'profile', component: () => import('../views/tabs/ProfileTab.vue') },
        { path: 'work', name: 'work', component: () => import('../views/tabs/WorkTab.vue') },
        { path: 'calendar', name: 'calendar', component: () => import('../views/tabs/CalendarTab.vue') },
        { path: 'billing', name: 'billing', component: () => import('../views/tabs/BillingTab.vue') },
        { path: 'notifications', name: 'notifications', component: () => import('../views/tabs/NotificationsTab.vue') },
        {
          path: 'contact',
          name: 'contact',
          meta: { requiresAuth: true },
          component: () => import('../views/ContactView.vue'),
        },
      ],
    },
  ],
})

router.beforeEach((to) => {
  const requiresAuth = to.matched.some((record) => record.meta.requiresAuth === true)
  if (!requiresAuth) {
    return true
  }
  if (!localStorage.getItem('auth_token')) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  return true
})

/** Counter ID must match `frontend/index.html` (Yandex.Metrika). */
const YANDEX_METRIKA_ID = 109160850

function sendMetrikaHit(fullPath: string) {
  const ym = window.ym
  if (typeof ym !== 'function') {
    return
  }
  ym(YANDEX_METRIKA_ID, 'hit', `${window.location.origin}${fullPath}`, {
    title: document.title,
  })
}

let skipInitialMetrikaHit = true
router.afterEach((to) => {
  if (skipInitialMetrikaHit) {
    skipInitialMetrikaHit = false
    return
  }
  sendMetrikaHit(to.fullPath)
})

export default router
