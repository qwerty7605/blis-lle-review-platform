<template>
  <AuthLayout
    eyebrow="Account recovery"
    title="Forgot Your Password?"
    subtitle="Enter your account email and we will send you a link to choose a new password."
    panel-heading="Locked out? You will be back to reviewing in a minute."
    panel-copy="We email a secure link to the address on your account. Your rooms, attempts, and scores stay exactly as you left them."
    :highlights="highlights"
  >
    <form class="auth-form" @submit.prevent="handleSubmit">
      <label class="field-group">
        <span class="field-label">Email address</span>
        <div class="field-wrap">
          <Mail :size="17" class="field-icon" />
          <input
            v-model="email"
            type="email"
            class="field-input"
            placeholder="you@lnu.edu.ph"
            autocomplete="email"
            v-bind="checks.bind('email')"
          />
        </div>
        <span v-if="checks.error('email')" :id="checks.errorId('email')" class="field-error" role="alert">
          {{ checks.error('email') }}
        </span>
      </label>

      <button type="submit" class="submit-btn" :disabled="isLoading">
        <span v-if="!isLoading">Request reset link</span>
        <span v-else>Sending...</span>
      </button>
    </form>

    <p v-if="message" class="alert alert-success">{{ message }}</p>
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
import { ref } from 'vue'
import { ArrowLeft, Clock, Mail, MailCheck, ShieldCheck } from 'lucide-vue-next'
import { useAuthStore } from '@/store/auth.store'
import { emailProblem, useFieldChecks } from '@/utils/fieldChecks'
import AuthLayout from './AuthLayout.vue'

const auth = useAuthStore()

const email = ref('')
const message = ref('')
const errorMsg = ref('')
const isLoading = ref(false)

const checks = useFieldChecks('forgot', {
  email: () => emailProblem(email.value, { emptyMessage: 'Enter your email address first.' }),
})

const highlights = [
  { icon: MailCheck, text: 'Reset link sent to your account email' },
  { icon: Clock, text: 'The link works for 60 minutes' },
  { icon: ShieldCheck, text: 'Your exam history is not affected' },
]

async function handleSubmit() {
  message.value = ''
  errorMsg.value = ''

  if (!checks.check()) return

  isLoading.value = true

  try {
    const data = await auth.forgotPassword(email.value.trim())

    // The reply is the same whether or not the address is on file, so there is
    // nothing here to tell someone which addresses have accounts.
    message.value = data?.message ?? 'If that address has an account, a reset link is on its way.'
  } catch (error) {
    errorMsg.value =
      error.response?.data?.message ?? 'Could not send the reset link. Try again shortly.'
  } finally {
    isLoading.value = false
  }
}
</script>
