import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api, ApiError, configureApi, jsonBody } from '@/services/api'
import type { Capabilities, User } from '@/types/api'

const noCapabilities: Capabilities = { manage_teams: false, manage_users: false, transfer_owner: false }

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const capabilities = ref<Capabilities>({ ...noCapabilities })
  const csrfToken = ref<string | null>(null)
  const initialized = ref(false)
  const busy = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const isSuperAdmin = computed(() => user.value?.role === 'super_admin')
  const isAdmin = computed(() => ['super_admin', 'team_admin'].includes(user.value?.role ?? ''))

  function clear() {
    user.value = null
    csrfToken.value = null
    capabilities.value = { ...noCapabilities }
  }

  configureApi({
    csrf: () => csrfToken.value,
    unauthorized: () => {
      clear()
      if (typeof window !== 'undefined') window.dispatchEvent(new Event('landingops:unauthorized'))
    },
  })

  async function restore() {
    if (initialized.value) return
    busy.value = true
    try {
      const data = await api<{ user: User; capabilities: Capabilities; csrf_token: string }>('/api/v1/me')
      user.value = data.user
      capabilities.value = data.capabilities
      csrfToken.value = data.csrf_token
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 401) throw error
      clear()
    } finally {
      initialized.value = true
      busy.value = false
    }
  }

  async function login(email: string, password: string) {
    busy.value = true
    try {
      const data = await api<{ user: User; csrf_token: string; expires_at: string }>('/api/v1/login', {
        method: 'POST', body: jsonBody({ email, password }),
      })
      user.value = data.user
      csrfToken.value = data.csrf_token
      initialized.value = true
      await refreshIdentity()
    } finally { busy.value = false }
  }

  async function refreshIdentity() {
    const data = await api<{ user: User; capabilities: Capabilities; csrf_token: string }>('/api/v1/me')
    user.value = data.user
    capabilities.value = data.capabilities
    csrfToken.value = data.csrf_token
  }

  async function logout(): Promise<boolean> {
    let completed = true
    try { if (user.value) await api<null>('/api/v1/logout', { method: 'POST' }) }
    catch { completed = false }
    finally { clear(); initialized.value = true }
    return completed
  }

  async function updateProfile(displayName: string) {
    const updated = await api<User>('/api/v1/me/profile', {
      method: 'PATCH', body: jsonBody({ display_name: displayName }),
    })
    user.value = updated
  }

  async function changePassword(currentPassword: string, newPassword: string) {
    await api<null>('/api/v1/me/password', {
      method: 'POST', body: jsonBody({ current_password: currentPassword, new_password: newPassword }),
    })
    clear()
    initialized.value = true
  }

  return { user, capabilities, csrfToken, initialized, busy, isAuthenticated, isSuperAdmin, isAdmin,
    clear, restore, login, logout, updateProfile, changePassword }
})
