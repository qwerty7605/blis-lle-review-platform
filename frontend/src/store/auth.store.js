import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api/auth.api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!user.value)

  // ─── actions ─────────────────────────────────────────────────────────────────
  async function login(identifier, password) {
    const { data } = await authApi.login({ identifier, password })
    user.value = data.user
    initialized.value = true
  }

  async function register(first_name, middle_name, last_name, student_id, year_level, email, password, password_confirmation, agreed) {
    const { data } = await authApi.register({
      first_name,
      middle_name,
      last_name,
      student_id,
      year_level,
      email,
      password,
      password_confirmation,
      agreed,
    })

    // Deliberately no session here. The account cannot be used until its
    // verification link is clicked, so there is nothing to sign in yet --
    // the caller shows a "check your inbox" state instead.
    return data
  }

  async function forgotPassword(email) {
    const { data } = await authApi.forgotPassword({ email })

    return data
  }

  async function resetPassword(payload) {
    const { data } = await authApi.resetPassword(payload)

    return data
  }
  async function resendVerification(email) {
    const { data } = await authApi.resendVerification({ email })

    return data
  }

  async function fetchMe(options = {}) {
    const { silent = false } = options

    try {
      const { data } = await authApi.me()
      user.value = data
      return data
    } catch (error) {
      user.value = null
      if (!silent && error?.response?.status !== 401) {
        throw error
      }
      return null
    } finally {
      initialized.value = true
    }
  }

  async function logout() {
    try {
      await authApi.logout()
    } catch (error) {
      // If token/session is already invalid, still proceed with local logout.
    } finally {
      user.value = null
      initialized.value = true
    }
  }

  return {
    user,
    initialized,
    isAuthenticated,
    login,
    register,
    resendVerification,
    forgotPassword,
    resetPassword,
    fetchMe,
    logout,
  }
})
