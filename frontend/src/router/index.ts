import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AdminLayout from '@/layouts/AdminLayout.vue'
import type { Role } from '@/types/api'

declare module 'vue-router' { interface RouteMeta { requiresAuth?: boolean; roles?: Role[] } }

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue') },
    {
      path: '/', component: AdminLayout, meta: { requiresAuth: true }, children: [
        { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
        { path: 'instances', name: 'instances', component: () => import('@/views/InstancesView.vue') },
        { path: 'templates', name: 'templates', component: () => import('@/views/TemplatesView.vue') },
        { path: 'domains', name: 'domains', component: () => import('@/views/DomainsView.vue') },
        { path: 'cloudflare', name: 'cloudflare', component: () => import('@/views/CloudflareConnectionsView.vue'), meta: { roles: ['super_admin'] } },
        { path: 'baota', name: 'baota', component: () => import('@/views/BaotaServerView.vue'), meta: { roles: ['super_admin'] } },
        { path: 'teams', name: 'teams', component: () => import('@/views/TeamsView.vue'), meta: { roles: ['super_admin'] } },
        { path: 'users', name: 'users', component: () => import('@/views/UsersView.vue'), meta: { roles: ['super_admin', 'team_admin'] } },
        { path: 'profile', name: 'profile', component: () => import('@/views/ProfileView.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue') },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) {
    try { await auth.restore() } catch { if (to.name !== 'login') return { name: 'login', query: { reason: 'api' } } }
  }
  if (to.meta.requiresAuth && !auth.isAuthenticated) return { name: 'login', query: { redirect: to.fullPath } }
  if (to.name === 'login' && auth.isAuthenticated) return { name: 'dashboard' }
  if (to.meta.roles && (!auth.user || !to.meta.roles.includes(auth.user.role))) return { name: 'dashboard', query: { denied: '1' } }
})

export default router
