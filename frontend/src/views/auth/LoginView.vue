<template>
  <AuthLayout
    eyebrow="Welcome back"
    title="Sign In to Continue"
    panel-heading="Review, practice, and track your progress."
    panel-copy="Open your reviewer workspace, take mock exams, and see how you are doing from one clean dashboard."
    :highlights="highlights"
  >
    <div v-if="apiError" class="alert alert-danger" role="alert">{{ apiError }}</div>

    <div v-if="notice" class="alert alert-success">{{ notice }}</div>

    <div v-if="unverifiedEmail" class="alert alert-info">
      <p>Link expired or never arrived? We can send a new one.</p>
      <button type="button" class="submit-btn" :disabled="isResending" @click="handleResend">
        <span v-if="!isResending">Resend verification email</span>
        <span v-else>Sending...</span>
      </button>
      <p v-if="resendMessage">{{ resendMessage }}</p>
    </div>

    <form class="auth-form" @submit.prevent="handleSubmit">
      <label class="field-group">
        <span class="field-label">Email or Student ID</span>
        <div class="field-wrap">
          <UserRound :size="17" class="field-icon" />
          <input
            v-model="form.identifier"
            type="text"
            class="field-input"
            placeholder="e.g. you@lnu.edu.ph or 1234567"
            autocomplete="username"
          />
        </div>
      </label>

      <label class="field-group">
        <span class="field-label">Password</span>
        <div class="field-wrap">
          <LockKeyhole :size="17" class="field-icon" />
          <input
            v-model="form.password"
            :type="showPw ? 'text' : 'password'"
            class="field-input field-input-with-toggle"
            placeholder="Enter your password"
            autocomplete="current-password"
          />
          <button
            type="button"
            class="field-toggle"
            :aria-label="showPw ? 'Hide password' : 'Show password'"
            :aria-pressed="showPw"
            @click="showPw = !showPw"
          >
            <EyeOff v-if="showPw" :size="16" />
            <Eye v-else :size="16" />
          </button>
        </div>
      </label>

      <div class="form-row">
        <label class="remember-wrap">
          <input type="checkbox" v-model="form.remember" />
          <span>Remember me</span>
        </label>
        <router-link to="/forgot-password" class="inline-link">Forgot password?</router-link>
      </div>

      <button type="submit" class="submit-btn" :disabled="isLoading">
        <span v-if="!isLoading">Sign In</span>
        <span v-else class="spinner" />
      </button>
    </form>

    <template #footer>
      No account yet?
      <router-link to="/register" class="inline-link strong">Create one</router-link>
    </template>
  </AuthLayout>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import AuthLayout from './AuthLayout.vue'
import {
  BarChart3,
  ClipboardCheck,
  Eye,
  EyeOff,
  LockKeyhole,
  ShieldCheck,
  UserRound,
} from 'lucide-vue-next'

const form = reactive({ identifier: '', password: '', remember: false })
const showPw = ref(false)
const isLoading = ref(false)
const apiError = ref('')

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const notice = ref('')
const unverifiedEmail = ref('')
const isResending = ref(false)
const resendMessage = ref('')

// The verification endpoint redirects here with the outcome in the query
// string, since the link is opened from an inbox with no session attached.
const VERIFICATION_NOTICES = {
  1: 'Email verified. You can sign in now.',
  expired: 'That verification link has expired. Sign in to request a new one.',
  invalid: 'That verification link is not valid. Sign in to request a new one.',
}

onMounted(() => {
  notice.value = VERIFICATION_NOTICES[route.query.verified] ?? ''
})

async function handleResend() {
  isResending.value = true
  resendMessage.value = ''

  try {
    const data = await auth.resendVerification(unverifiedEmail.value)
    resendMessage.value = data?.message ?? 'Check your inbox.'
  } catch (error) {
    resendMessage.value =
      error.response?.data?.message ?? 'Could not send right now. Try again shortly.'
  } finally {
    isResending.value = false
  }
}

const highlights = [
  { icon: ClipboardCheck, text: 'Practice tests and exam drills' },
  { icon: BarChart3, text: 'Performance analytics by subject' },
  { icon: ShieldCheck, text: 'Secure role-based access control' },
]

async function handleSubmit() {
  apiError.value = ''

  if (!form.identifier || !form.password) {
    apiError.value = 'Email or Student ID and password are required.'
    return
  }

  isLoading.value = true
  try {
    await auth.login(form.identifier, form.password)
    await router.push('/dashboard')
  } catch (error) {
    apiError.value = error.response?.data?.message ?? 'Invalid credentials. Please try again.'
    unverifiedEmail.value = error.response?.data?.unverified
      ? (error.response.data.email ?? '')
      : ''
  } finally {
    isLoading.value = false
  }
}
</script>

<style scoped>
/* Shared field, alert and layout styles live in auth.css (see AuthLayout.vue). */

.form-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 13px;
}

.remember-wrap {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--lnu-text-muted);
}

.remember-wrap input {
  accent-color: var(--lnu-navy);
}

/* Each on one line. Allowed to break, both did at 320px, four short lines
   where two fit; below that the link drops under the checkbox instead. */
.form-row {
  flex-wrap: wrap;
}

.remember-wrap,
.form-row .inline-link {
  white-space: nowrap;
}

@media (max-width: 340px) {
  .form-row {
    gap: 8px;
    font-size: 12.5px;
  }
}

@media (pointer: coarse) {
  .remember-wrap {
    min-height: 44px;
  }

  .remember-wrap input {
    width: 20px;
    height: 20px;
  }
}
</style>
