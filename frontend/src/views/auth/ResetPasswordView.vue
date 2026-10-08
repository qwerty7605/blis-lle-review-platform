<template>
  <AuthLayout
    eyebrow="Account recovery"
    title="Choose a New Password"
    panel-heading="One new password and you are back in."
    panel-copy="Pick something you have not used before. Once it is saved, sign in again to return to your rooms and exams."
    :highlights="highlights"
  >
    <p v-if="email" class="reset-target">
      Setting a new password for <strong>{{ email }}</strong>.
    </p>
    <p v-else class="reset-target">This reset link looks incomplete.</p>

    <p v-if="!linkIsUsable" class="alert alert-danger" role="alert">
      Open the link from your email exactly as it was sent. If it was cut off,
      request a new one from the forgot-password page.
    </p>

    <form v-else-if="!done" class="auth-form" @submit.prevent="handleSubmit">
      <div class="field-group">
        <label class="field-label" for="reset-password">New password</label>
        <div class="field-wrap">
          <LockKeyhole :size="17" class="field-icon" />
          <input
            id="reset-password"
            v-model="password"
            :type="showPw ? 'text' : 'password'"
            class="field-input field-input-with-toggle"
            autocomplete="new-password"
            placeholder="At least 8 characters"
          />
          <button
            type="button"
            class="field-toggle"
            :aria-label="showPw ? 'Hide password' : 'Show password'"
            :aria-pressed="showPw"
            @click="showPw = !showPw"
          >
            <component :is="showPw ? EyeOff : Eye" :size="16" />
          </button>
        </div>

        <ul class="pw-rules">
          <li v-for="rule in pwChecklist" :key="rule.key" :class="{ met: rule.met }">
            <component :is="rule.met ? Check : X" :size="13" />
            {{ rule.label }}
          </li>
        </ul>
      </div>

      <div class="field-group">
        <label class="field-label" for="reset-password-confirmation">Confirm new password</label>
        <div class="field-wrap">
          <LockKeyhole :size="17" class="field-icon" />
          <input
            id="reset-password-confirmation"
            v-model="passwordConfirmation"
            :type="showPw ? 'text' : 'password'"
            class="field-input"
            autocomplete="new-password"
            placeholder="Type it again"
          />
        </div>
      </div>

      <button type="submit" class="submit-btn" :disabled="isLoading">
        <span v-if="!isLoading">Update password</span>
        <span v-else>Updating...</span>
      </button>
    </form>

    <p v-if="done" class="alert alert-success">
      {{ message }} You have been signed out everywhere else.
    </p>

    <p v-if="errorMsg" class="alert alert-danger" role="alert">{{ errorMsg }}</p>

    <template #footer>
      <router-link to="/login" class="back-link">
        <ArrowLeft :size="14" />
        Back to login
      </router-link>
    </template>
  </AuthLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  ArrowLeft,
  Check,
  Eye,
  EyeOff,
  KeyRound,
  LockKeyhole,
  LogOut,
  ShieldCheck,
  X,
} from 'lucide-vue-next'
import { useAuthStore } from '@/store/auth.store'
import { firstPasswordProblem, passwordChecklist } from '@/utils/password'
import AuthLayout from './AuthLayout.vue'

const route = useRoute()
const auth = useAuthStore()

// Both arrive in the query string of the link sent by email.
const token = String(route.query.token ?? '')
const email = String(route.query.email ?? '')

const password = ref('')
const passwordConfirmation = ref('')
const showPw = ref(false)
const isLoading = ref(false)
const errorMsg = ref('')
const message = ref('')
const done = ref(false)

const highlights = [
  { icon: KeyRound, text: 'The checklist shows what a password needs' },
  { icon: LogOut, text: 'Other devices are signed out when you save' },
  { icon: ShieldCheck, text: 'Your exam history is not affected' },
]

const linkIsUsable = computed(() => token !== '' && email !== '')
const pwChecklist = computed(() => passwordChecklist(password.value))

async function handleSubmit() {
  errorMsg.value = ''

  const passwordProblem = firstPasswordProblem(password.value)

  if (passwordProblem) {
    errorMsg.value = passwordProblem
    return
  }

  if (password.value !== passwordConfirmation.value) {
    errorMsg.value = 'The two passwords do not match.'
    return
  }

  isLoading.value = true

  try {
    const data = await auth.resetPassword({
      token,
      email,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })

    message.value = data?.message ?? 'Password updated.'
    done.value = true
  } catch (error) {
    errorMsg.value =
      error.response?.data?.message ?? 'Could not update the password. Request a new link.'
  } finally {
    isLoading.value = false
  }
}
</script>

<style scoped>
/* Shared field, alert and layout styles live in auth.css (see AuthLayout.vue). */

.reset-target {
  margin: 0 0 16px;
  text-align: center;
  font-size: 14px;
  color: var(--lnu-text-muted);
  overflow-wrap: anywhere;
}
</style>
