<template>
  <main class="forgot-shell">
    <section class="forgot-card">
      <div class="forgot-icon-wrap">
        <LockKeyhole :size="22" />
      </div>

      <h1>Choose a new password</h1>
      <p v-if="email">Setting a new password for <strong>{{ email }}</strong>.</p>
      <p v-else>This reset link looks incomplete.</p>

      <p v-if="!linkIsUsable" class="status-msg status-error">
        Open the link from your email exactly as it was sent. If it was cut off,
        request a new one from the forgot-password page.
      </p>

      <form v-else-if="!done" class="forgot-form" @submit.prevent="handleSubmit">
        <label class="field-group">
          <span>New password</span>
          <div class="field-wrap">
            <LockKeyhole :size="16" class="field-icon" />
            <input
              v-model="password"
              :type="showPw ? 'text' : 'password'"
              class="field-input"
              autocomplete="new-password"
              placeholder="At least 8 characters"
            />
            <button type="button" class="peek-btn" @click="showPw = !showPw">
              <component :is="showPw ? EyeOff : Eye" :size="16" />
            </button>
          </div>

          <ul class="pw-rules">
            <li v-for="rule in pwChecklist" :key="rule.key" :class="{ met: rule.met }">
              <component :is="rule.met ? Check : X" :size="13" />
              {{ rule.label }}
            </li>
          </ul>
        </label>

        <label class="field-group">
          <span>Confirm new password</span>
          <div class="field-wrap">
            <LockKeyhole :size="16" class="field-icon" />
            <input
              v-model="passwordConfirmation"
              :type="showPw ? 'text' : 'password'"
              class="field-input"
              autocomplete="new-password"
              placeholder="Type it again"
            />
          </div>
        </label>

        <button type="submit" class="submit-btn" :disabled="isLoading">
          <span v-if="!isLoading">Update password</span>
          <span v-else>Updating...</span>
        </button>
      </form>

      <p v-if="done" class="status-msg">
        {{ message }} You have been signed out everywhere else.
      </p>

      <p v-if="errorMsg" class="status-msg status-error">{{ errorMsg }}</p>

      <router-link to="/login" class="back-link">
        <ArrowLeft :size="14" />
        Back to login
      </router-link>
    </section>
  </main>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, Check, Eye, EyeOff, LockKeyhole, X } from 'lucide-vue-next'
import { useAuthStore } from '@/store/auth.store'
import { firstPasswordProblem, passwordChecklist } from '@/utils/password'

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
.forgot-shell {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px 16px;
  background:
    radial-gradient(circle at 15% 8%, rgba(26, 35, 126, 0.12), transparent 30%),
    radial-gradient(circle at 85% 92%, rgba(201, 168, 76, 0.24), transparent 35%),
    var(--lnu-bg);
}

.forgot-card {
  width: 100%;
  max-width: 480px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.97), rgba(253, 246, 227, 0.95));
  border: 1px solid rgba(26, 35, 126, 0.16);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: 28px;
}

.forgot-icon-wrap {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--lnu-navy-light);
  background: rgba(26, 35, 126, 0.1);
}

.forgot-card h1 {
  margin: 14px 0 8px;
  font-size: 29px;
  line-height: 1.2;
  color: var(--lnu-text);
}

.forgot-card p {
  margin: 0;
  font-size: 15px;
  color: var(--lnu-text-muted);
}

.forgot-form {
  margin-top: 18px;
  display: grid;
  gap: 14px;
}

.field-group {
  display: grid;
  gap: 8px;
}

.field-group span {
  font-size: 14px;
  font-weight: 600;
  color: var(--lnu-text);
}

.field-wrap {
  position: relative;
}

.field-icon {
  position: absolute;
  top: 50%;
  left: 12px;
  transform: translateY(-50%);
  color: var(--lnu-navy-light);
}

.field-input {
  width: 100%;
  height: 44px;
  border: 1px solid rgba(13, 21, 71, 0.2);
  border-radius: var(--radius-sm);
  padding: 0 40px 0 38px;
  color: var(--lnu-text);
  background: rgba(255, 255, 255, 0.9);
}

.field-input:focus {
  outline: none;
  border-color: var(--lnu-navy);
  box-shadow: var(--focus-ring);
}

.pw-rules {
  list-style: none;
  margin: 8px 0 0;
  padding: 0;
  display: grid;
  gap: 4px;
}

.pw-rules li {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 400;
  color: var(--lnu-text-muted);
}

.pw-rules li.met {
  color: var(--lnu-success);
}

.peek-btn {
  position: absolute;
  top: 50%;
  right: 10px;
  transform: translateY(-50%);
  border: 0;
  background: transparent;
  color: var(--lnu-navy-light);
  cursor: pointer;
  display: inline-flex;
}

/* .submit-btn is shared globally, see style.css */

.status-msg {
  margin-top: 12px;
  padding: 10px 12px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(46, 125, 50, 0.24);
  background: rgba(46, 125, 50, 0.1);
  color: var(--lnu-success);
  font-size: 14px;
}

.status-error {
  border-color: rgba(198, 40, 40, 0.24);
  background: rgba(198, 40, 40, 0.1);
  color: var(--lnu-danger);
}

.back-link {
  margin-top: 16px;
  font-size: 14px;
  color: var(--lnu-navy-light);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.back-link:hover {
  text-decoration: underline;
}
</style>
