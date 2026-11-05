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
      <button type="button" class="submit-btn" :disabled="resendDisabled" @click="handleResend">
        <span v-if="isResending">Sending...</span>
        <span v-else-if="resendWaitSeconds > 0">Resend again in {{ resendWaitSeconds }}s</span>
        <span v-else>Resend verification email</span>
      </button>
      <p v-if="resendMessage" role="status">{{ resendMessage }}</p>
      <p v-if="resendTooMany" class="resend-limit" role="alert">Too many attempts. Try again later.</p>
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
            v-bind="checks.bind('identifier')"
          />
        </div>
        <span v-if="checks.error('identifier')" :id="checks.errorId('identifier')" class="field-error" role="alert">
          {{ checks.error('identifier') }}
        </span>
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
            v-bind="checks.bind('password')"
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
        <span v-if="checks.error('password')" :id="checks.errorId('password')" class="field-error" role="alert">
          {{ checks.error('password') }}
        </span>
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
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/store/auth.store'
import { requiredProblem, signInIdentifierProblem, useFieldChecks } from '@/utils/fieldChecks'
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

const checks = useFieldChecks('sign-in', {
  identifier: () => signInIdentifierProblem(form.identifier),
  password: () => requiredProblem(form.password, 'Enter your password.'),
})
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

  // Sent here because the session ended under an open page (see main.js).
  if (!notice.value && route.query.ended === '1') {
    notice.value = 'Your session ended. Sign in again to carry on.'
  }

  // From the link that confirms a new sign-in email (Settings page).
  const emailChange = {
    1: 'Your email address was changed. Sign in with the new one.',
    expired: 'That email change link has expired or was altered. Ask for a new one from Settings.',
    invalid: 'That email change link is no longer valid. Ask for a new one from Settings.',
  }[route.query.email_changed]

  if (emailChange) notice.value = emailChange

  if (route.query.ended === 'idle') {
    notice.value = 'You were signed out because the page was left unused for a while. Sign in again to carry on.'
  }

  if (route.query.ended === 'disabled') {
    apiError.value = 'This account has been disabled. Ask an administrator if that is a mistake.'
  }
})

/*
 * Resending the verification email is paced: 20 seconds between one and the
 * next, so a second press is not made before the first email has had time to
 * arrive, and three in an hour for one address, which is all the server will
 * send (see the 'verification' limiter in AppServiceProvider). The fourth
 * press is told so instead of being sent off to be refused.
 *
 * The times are kept for the browser tab, per address, so reloading the page
 * does not hand the wait back.
 */
const RESEND_WAIT_MS = 20 * 1000
const RESEND_LIMIT = 3
const RESEND_WINDOW_MS = 60 * 60 * 1000

const resendSentAt = ref([])
const resendTooMany = ref(false)
const resendClock = ref(Date.now())
let resendTicker = null

const resendWaitSeconds = computed(() => {
  const last = resendSentAt.value[resendSentAt.value.length - 1]
  if (!last) return 0

  return Math.max(0, Math.ceil((last + RESEND_WAIT_MS - resendClock.value) / 1000))
})

const resendDisabled = computed(() => (
  isResending.value || resendWaitSeconds.value > 0 || resendTooMany.value
))

function resendStorageKey(email) {
  return `resend-verification:${String(email).toLowerCase()}`
}

function recentResends(times) {
  return times.filter((time) => Date.now() - time < RESEND_WINDOW_MS)
}

function loadResendTimes(email) {
  try {
    const stored = JSON.parse(window.sessionStorage.getItem(resendStorageKey(email)) ?? '[]')

    return recentResends(Array.isArray(stored) ? stored.filter(Number.isFinite) : [])
  } catch {
    // Storage can be switched off; the page then paces from memory alone.
    return []
  }
}

function saveResendTimes(email) {
  try {
    window.sessionStorage.setItem(resendStorageKey(email), JSON.stringify(resendSentAt.value))
  } catch {
    // Nothing to do: see loadResendTimes.
  }
}

// Counts the wait down a second at a time, and stops when there is none.
function runResendClock() {
  resendClock.value = Date.now()

  if (resendWaitSeconds.value === 0) {
    window.clearInterval(resendTicker)
    resendTicker = null
    return
  }

  if (resendTicker === null) {
    resendTicker = window.setInterval(runResendClock, 1000)
  }
}

// A different account's sign-in was refused: its own count, not the last one's.
watch(unverifiedEmail, (email) => {
  resendMessage.value = ''
  resendTooMany.value = false
  resendSentAt.value = email ? loadResendTimes(email) : []
  runResendClock()
})

onBeforeUnmount(() => {
  window.clearInterval(resendTicker)
})

async function handleResend() {
  if (resendDisabled.value) return

  resendSentAt.value = recentResends(resendSentAt.value)

  if (resendSentAt.value.length >= RESEND_LIMIT) {
    resendMessage.value = ''
    resendTooMany.value = true
    return
  }

  isResending.value = true
  resendMessage.value = ''

  try {
    const data = await auth.resendVerification(unverifiedEmail.value)
    resendMessage.value = data?.message ?? 'Check your inbox.'

    // Counted from when the server answered, so the wait is never shorter
    // than the one the email is actually given.
    resendSentAt.value = [...resendSentAt.value, Date.now()]
    saveResendTimes(unverifiedEmail.value)
    runResendClock()
  } catch (error) {
    // 429: the server has already sent this address all it will this hour,
    // from this browser or another.
    if (error.response?.status === 429) {
      resendTooMany.value = true
    } else {
      resendMessage.value =
        error.response?.data?.message ?? 'Could not send right now. Try again shortly.'
    }
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

  if (!checks.check()) return

  isLoading.value = true
  try {
    await auth.login(form.identifier, form.password, form.remember)
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

.resend-limit {
  color: var(--dk-fg-lnu-danger, var(--lnu-danger));
  font-weight: 600;
}

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
  accent-color: var(--dk-fg-lnu-navy, var(--lnu-navy));
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
