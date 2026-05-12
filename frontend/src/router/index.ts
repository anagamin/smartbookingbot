import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/', name: 'home', component: HomeView },
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
      path: '/app',
      component: () => import('../views/DashboardLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        { path: '', redirect: '/app/profile' },
        { path: 'profile', name: 'profile', component: () => import('../views/tabs/ProfileTab.vue') },
        { path: 'work', name: 'work', component: () => import('../views/tabs/WorkTab.vue') },
        { path: 'calendar', name: 'calendar', component: () => import('../views/tabs/CalendarTab.vue') },
        { path: 'logs', name: 'logs', component: () => import('../views/tabs/LogsTab.vue') },
        { path: 'billing', name: 'billing', component: () => import('../views/tabs/BillingTab.vue') },
        { path: 'notifications', name: 'notifications', component: () => import('../views/tabs/NotificationsTab.vue') },
      ],
    },
  ],
})

router.beforeEach((to) => {
  if (!to.meta.requiresAuth) {
    return true
  }
  if (!localStorage.getItem('auth_token')) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  return true
})

export default router
