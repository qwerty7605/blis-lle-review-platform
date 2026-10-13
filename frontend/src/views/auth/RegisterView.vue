<template>
  <AuthLayout
    wide
    eyebrow="Registration"
    title="Create Your Account"
    subtitle="Complete your details to start using the platform."
    panel-heading="Create your reviewer account."
    panel-copy="Register once and get your exam rooms, analytics, and mock examination modules in one place."
    :highlights="highlights"
  >
        <div v-if="apiError" class="alert alert-danger" role="alert">{{ apiError }}</div>

        <div v-if="pendingEmail" class="alert alert-success">
          <p><strong>Almost there.</strong></p>
          <p>
            We sent a verification link to <strong>{{ pendingEmail }}</strong>.
            Open it to activate your account, then sign in.
          </p>
          <button type="button" class="submit-btn" @click="router.push('/login')">
            Back to sign in
          </button>
        </div>

        <form v-if="!pendingEmail" class="auth-form" @submit.prevent="handleSubmit">
          <div class="field-grid auth-form-grid">
            <div class="field-group">
              <label class="field-label" :for="'register-first_name'">First name</label>
              <div class="field-wrap">
                <UserRound :size="17" class="field-icon" />
                <input
                  id="register-first_name"
                  v-model="form.first_name"
                  type="text"
                  class="field-input"
                  :class="{ 'field-input-error': fieldErrors.first_name }"
                  placeholder="e.g. Juan"
                  autocomplete="given-name"
                  :aria-invalid="Boolean(fieldErrors.first_name)"
                  :aria-describedby="fieldErrors.first_name ? 'register-first_name-error' : undefined"
                  @input="clearFieldError('first_name')"
                />
              </div>
              <p v-if="fieldErrors.first_name" :id="'register-first_name-error'" class="field-error" role="alert">
                {{ fieldErrors.first_name }}
              </p>
            </div>

            <div class="field-group">
              <label class="field-label" for="register-middle_name">Middle name (optional)</label>
              <div class="field-wrap">
                <UserRound :size="17" class="field-icon" />
                <input
                  id="register-middle_name"
                  v-model="form.middle_name"
                  type="text"
                  class="field-input"
                  placeholder="e.g. Santos"
                  autocomplete="additional-name"
                />
              </div>
            </div>
          </div>

          <div class="field-grid auth-form-grid">
            <div class="field-group">
              <label class="field-label" :for="'register-last_name'">Last name</label>
              <div class="field-wrap">
                <UserRound :size="17" class="field-icon" />
                <input
                  id="register-last_name"
                  v-model="form.last_name"
                  type="text"
                  class="field-input"
                  :class="{ 'field-input-error': fieldErrors.last_name }"
                  placeholder="e.g. Dela Cruz"
                  autocomplete="family-name"
                  :aria-invalid="Boolean(fieldErrors.last_name)"
                  :aria-describedby="fieldErrors.last_name ? 'register-last_name-error' : undefined"
                  @input="clearFieldError('last_name')"
                />
              </div>
              <p v-if="fieldErrors.last_name" :id="'register-last_name-error'" class="field-error" role="alert">
                {{ fieldErrors.last_name }}
              </p>
            </div>

            <div class="field-group">
              <label class="field-label" :for="'register-student_id'">Student ID</label>
              <div class="field-wrap">
                <Hash :size="17" class="field-icon" />
                <input
                  id="register-student_id"
                  v-model="form.student_id"
                  type="text"
                  class="field-input"
                  :class="{ 'field-input-error': fieldErrors.student_id }"
                  placeholder="e.g. 2300001"
                  autocomplete="off"
                  :aria-invalid="Boolean(fieldErrors.student_id)"
                  :aria-describedby="fieldErrors.student_id ? 'register-student_id-error' : undefined"
                  inputmode="numeric"
                  pattern="[0-9]*"
                  maxlength="10"
                  @input="clearFieldError('student_id')"
                />
              </div>
              <p v-if="fieldErrors.student_id" :id="'register-student_id-error'" class="field-error" role="alert">
                {{ fieldErrors.student_id }}
              </p>
            </div>
          </div>

          <div class="field-group">
            <label class="field-label" for="register-year_level">Current year level</label>
            <div class="field-wrap field-wrap-select">
              <GraduationCap :size="17" class="field-icon" />
              <select
                id="register-year_level"
                v-model="form.year_level"
                class="field-input field-select"
                :class="{ 'field-input-error': fieldErrors.year_level }"
                :aria-invalid="Boolean(fieldErrors.year_level)"
                :aria-describedby="fieldErrors.year_level ? 'register-year_level-error' : undefined"
                @change="clearFieldError('year_level')"
              >
                <option value="">Select year level</option>
                <option v-for="level in yearLevelOptions" :key="level.value" :value="level.value">
                  {{ level.label }}
                </option>
              </select>
            </div>
            <p v-if="fieldErrors.year_level" id="register-year_level-error" class="field-error" role="alert">
              {{ fieldErrors.year_level }}
            </p>
          </div>

          <div class="field-group">
            <label class="field-label" for="register-email">Email address</label>
            <div class="field-wrap">
              <Mail :size="17" class="field-icon" />
              <input
                id="register-email"
                v-model="form.email"
                type="email"
                class="field-input"
                :class="{ 'field-input-error': fieldErrors.email }"
                placeholder="you@lnu.edu.ph"
                autocomplete="email"
                :aria-invalid="Boolean(fieldErrors.email)"
                :aria-describedby="fieldErrors.email ? 'register-email-error' : undefined"
                @input="clearFieldError('email')"
              />
            </div>
            <p v-if="fieldErrors.email" id="register-email-error" class="field-error" role="alert">
              {{ fieldErrors.email }}
            </p>
          </div>

          <div class="field-grid">
            <div class="field-group">
              <label class="field-label" for="register-password">Password</label>
              <div class="field-wrap">
                <LockKeyhole :size="17" class="field-icon" />
                <input
                  id="register-password"
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  class="field-input field-input-with-toggle"
                  :class="{ 'field-input-error': fieldErrors.password }"
                  placeholder="Min. 8 characters"
                  autocomplete="new-password"
                  :aria-invalid="Boolean(fieldErrors.password)"
                  :aria-describedby="fieldErrors.password ? 'register-password-error' : undefined"
                  @input="clearFieldError('password')"
                />
                <button
                  type="button"
                  class="field-toggle"
                  :aria-label="showPassword ? 'Hide password' : 'Show password'"
                  :aria-pressed="showPassword"
                  @click="showPassword = !showPassword"
                >
                  <EyeOff v-if="showPassword" :size="16" />
                  <Eye v-else :size="16" />
                </button>
              </div>
              <p v-if="fieldErrors.password" id="register-password-error" class="field-error" role="alert">
                {{ fieldErrors.password }}
              </p>
            </div>

            <div class="field-group">
              <label class="field-label" for="register-password_confirmation">Confirm password</label>
              <div class="field-wrap">
                <LockKeyhole :size="17" class="field-icon" />
                <input
                  id="register-password_confirmation"
                  v-model="form.password_confirmation"
                  :type="showPasswordConfirmation ? 'text' : 'password'"
                  class="field-input field-input-with-toggle"
                  :class="{ 'field-input-error': pwMismatch || fieldErrors.password_confirmation }"
                  placeholder="Re-enter password"
                  autocomplete="new-password"
                  :aria-invalid="Boolean(pwMismatch || fieldErrors.password_confirmation)"
                  :aria-describedby="
                    pwMismatch || fieldErrors.password_confirmation
                      ? 'register-password_confirmation-error'
                      : undefined
                  "
                  @input="clearFieldError('password_confirmation')"
                />
                <button
                  type="button"
                  class="field-toggle"
                  :aria-label="showPasswordConfirmation ? 'Hide password' : 'Show password'"
                  :aria-pressed="showPasswordConfirmation"
                  @click="showPasswordConfirmation = !showPasswordConfirmation"
                >
                  <EyeOff v-if="showPasswordConfirmation" :size="16" />
                  <Eye v-else :size="16" />
                </button>
              </div>
              <p
                v-if="pwMismatch || fieldErrors.password_confirmation"
                id="register-password_confirmation-error"
                class="field-error"
                role="alert"
              >
                {{ pwMismatch ? 'Passwords do not match.' : fieldErrors.password_confirmation }}
              </p>
            </div>
          </div>

          <div class="pw-strength" v-if="form.password">
            <div class="pw-track">
              <div class="pw-fill" :style="{ width: `${pwStrength.pct}%`, background: pwStrength.color }" />
            </div>
            <span class="pw-label" :style="{ color: pwStrength.color }">{{ pwStrength.label }}</span>

            <ul class="pw-rules">
              <li v-for="rule in pwChecklist" :key="rule.key" :class="{ met: rule.met }">
                <component :is="rule.met ? Check : X" :size="13" />
                {{ rule.label }}
              </li>
            </ul>
          </div>

          <div class="terms-section">
            <label class="terms-wrap">
              <input
                id="register-agreed"
                type="checkbox"
                v-model="form.agreed"
                :aria-invalid="Boolean(fieldErrors.agreed)"
                aria-describedby="register-agreed-note"
                @click="handleAgreeClick"
                @change="clearFieldError('agreed')"
              />
              <span>
                I agree to the
                <a
                  href="#"
                  class="inline-link strong"
                  :style="policiesRead ? 'color: var(--lnu-success)' : ''"
                  @click.prevent="openPolicyModal"
                  >Terms of Service and Privacy Policy</a
                >.
              </span>
            </label>
            <!-- One line under the box: the hint, or the error in its place.
                 Both at once said the same sentence twice. -->
            <p v-if="fieldErrors.agreed" id="register-agreed-note" class="field-error" role="alert">
              {{ fieldErrors.agreed }}
            </p>
            <p v-else-if="!policiesRead" id="register-agreed-note" class="terms-helper">
              Open and read both documents before agreeing.
            </p>
          </div>

          <button type="submit" class="submit-btn" :disabled="isLoading" :aria-busy="isLoading">
            <span v-if="isLoading" class="spinner" aria-hidden="true" />
            <span>{{ isLoading ? 'Creating account...' : 'Create Account' }}</span>
          </button>
        </form>

        <template #footer>
          Already registered?
          <router-link to="/login" class="inline-link strong">Sign in</router-link>
        </template>
  </AuthLayout>

  <Teleport to="body">
    <div
      v-if="policyModalOpen"
      class="policy-modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-label="Terms of Service and Privacy Policy"
      @click.self="closePolicyModal"
    >
      <section class="policy-modal">
        <header class="policy-modal-header">
          <h3>Terms of Service and Privacy Policy</h3>
          <button type="button" class="policy-close-btn" @click="closePolicyModal">Close</button>
        </header>

        <div ref="policyBodyRef" class="policy-modal-body" @scroll="handlePolicyScroll">
          <p class="policy-updated">{{ legalUpdatedLabel }}</p>
          <p v-if="legalLoading" class="policy-loading">Loading latest policy content...</p>
          <p v-else-if="legalError" class="policy-warning">{{ legalError }}</p>
          <article class="policy-article">
            <h4>Terms of Service</h4>
            <p class="policy-body-text">{{ legalTexts.terms_of_use_text }}</p>
          </article>
          <article class="policy-article">
            <h4>Privacy Policy</h4>
            <p class="policy-body-text">{{ legalTexts.privacy_policy_text }}</p>
          </article>
        </div>

        <footer class="policy-modal-footer">
          <span class="policy-scroll-note">
            {{
              policyReadyToConfirm
                ? 'You reached the end of both documents.'
                : 'Scroll to the bottom to mark as read.'
            }}
          </span>
          <button
            type="button"
            class="submit-btn policy-mark-btn"
            :disabled="!policyReadyToConfirm || legalLoading"
            @click="markPolicyAsRead"
          >
            Mark as Read
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { settingsApi } from '@/api/settings.api'
import { useAuthStore } from '@/store/auth.store'
import { firstPasswordProblem, passwordChecklist, passwordStrength } from '@/utils/password'
import AuthLayout from './AuthLayout.vue'
import {
  BarChart3,
  Check,
  ClipboardCheck,
  Eye,
  EyeOff,
  GraduationCap,
  Hash,
  LockKeyhole,
  Mail,
  ShieldCheck,
  UserRound,
  X,
} from 'lucide-vue-next'

const form = reactive({
  first_name: '',
  middle_name: '',
  last_name: '',
  student_id: '',
  year_level: '',
  email: '',
  password: '',
  password_confirmation: '',
  agreed: false,
})

const showPassword = ref(false)
const showPasswordConfirmation = ref(false)
const isLoading = ref(false)
const apiError = ref('')

/*
 * One message per field instead of a single "Please complete all required
 * fields." above a nine-field form. That banner could not say *which* field
 * was wrong, so a too-short student ID looked identical to an empty one -- and
 * the server's own field-level errors ("that student ID is already taken")
 * were being collapsed into the bare word "Validation failed." on the way in.
 */
const fieldErrors = reactive({
  first_name: '',
  last_name: '',
  student_id: '',
  year_level: '',
  email: '',
  password: '',
  password_confirmation: '',
  agreed: '',
})

// Order matters: the first error in this list is the one focused, so the
// reader lands on the topmost problem rather than an arbitrary one.
const FIELD_ORDER = [
  'first_name',
  'last_name',
  'student_id',
  'year_level',
  'email',
  'password',
  'password_confirmation',
  'agreed',
]

const hasFieldErrors = computed(() => FIELD_ORDER.some((field) => fieldErrors[field]))

function clearFieldError(field) {
  fieldErrors[field] = ''
}

function resetFieldErrors() {
  FIELD_ORDER.forEach((field) => {
    fieldErrors[field] = ''
  })
}

/*
 * Laravel answers 422 with { errors: { student_id: ["..."], ... } }. Dropping
 * that on the floor and showing only `message` is what made every server-side
 * rejection read as "Validation failed."
 */
function applyServerErrors(errors) {
  let matched = false

  Object.entries(errors ?? {}).forEach(([field, messages]) => {
    if (!(field in fieldErrors)) return

    fieldErrors[field] = Array.isArray(messages) ? String(messages[0]) : String(messages)
    matched = true
  })

  return matched
}

async function focusFirstError() {
  const firstField = FIELD_ORDER.find((field) => fieldErrors[field])
  if (!firstField) return

  await nextTick()
  document.getElementById(`register-${firstField}`)?.focus()
}
const pendingEmail = ref('')
/*
 * The Terms of Service and the Privacy Policy open together in one dialog and
 * are marked as read together. They used to be two links, each with its own
 * scroll-and-confirm, so agreeing to a single sentence took two separate trips.
 */
const policyModalOpen = ref(false)
const policyBodyRef = ref(null)
const policyReadyToConfirm = ref(false)
const policiesRead = ref(false)
const legalLoading = ref(false)
const legalError = ref('')
const legalUpdatedAt = ref('')

const auth = useAuthStore()
const router = useRouter()

const highlights = [
  { icon: ClipboardCheck, text: 'Room-based class exam management' },
  { icon: BarChart3, text: 'Clear analytics and score tracking' },
  { icon: ShieldCheck, text: 'Role-based access for each account' },
]

const yearLevelOptions = [
  { value: '1', label: '1st Year' },
  { value: '2', label: '2nd Year' },
  { value: '3', label: '3rd Year' },
  { value: '4', label: '4th Year' },
]

const defaultTermsOfUseText = `1. Acceptable Use
Use the platform only for official academic review and examination activities. Sharing accounts, impersonating users, or attempting to bypass exam controls is prohibited.

2. Account Responsibility
You are responsible for keeping your credentials secure. Actions performed using your account are treated as your own unless officially reported as compromised.

3. Academic Integrity
Cheating, unauthorized collaboration, copying exam content, or distributing question material may result in account suspension and disciplinary action.

4. Availability and Changes
System features may be updated or temporarily unavailable for maintenance. Administrators may enforce policy updates to keep the platform compliant and secure.

5. Enforcement
Violations can lead to investigation, removal of access, and formal reporting to relevant academic authorities.`

const defaultPrivacyPolicyText = `1. Data We Collect
The platform stores identity details (name, student ID, email), role information, room enrollments, and exam performance records required for academic operations.

2. How Data Is Used
Collected data is used to authenticate users, manage rooms and exams, generate reports, and maintain audit logs for security and accountability.

3. Data Access
Access to personal and exam-related data is role-based. Authorized staff and administrators can access only the data needed for their responsibilities.

4. Data Retention
Records are retained based on institutional requirements for academic continuity, reporting, and account recovery workflows.

5. Security and Contact
Reasonable safeguards are applied to protect stored data. Report privacy concerns or account issues to the institution administrators for assistance.`

const legalTexts = reactive({
  terms_of_use_text: defaultTermsOfUseText,
  privacy_policy_text: defaultPrivacyPolicyText,
})

const pwMismatch = computed(() =>
  form.password_confirmation.length > 0 && form.password !== form.password_confirmation,
)
const legalUpdatedLabel = computed(() => {
  if (!legalUpdatedAt.value) {
    return 'Last updated: March 5, 2026'
  }

  const parsed = new Date(legalUpdatedAt.value)
  if (Number.isNaN(parsed.getTime())) {
    return 'Last updated: March 5, 2026'
  }

  return `Last updated: ${parsed.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })}`
})

// Tracks the requirements the server actually enforces rather than guessing
// at entropy, so a full bar means the form will be accepted.
const pwStrength = computed(() => passwordStrength(form.password))
const pwChecklist = computed(() => passwordChecklist(form.password))

function updatePolicyReadiness() {
  if (legalLoading.value) {
    policyReadyToConfirm.value = false
    return
  }

  const bodyEl = policyBodyRef.value
  if (!bodyEl) return

  const scrollBottom = bodyEl.scrollTop + bodyEl.clientHeight
  policyReadyToConfirm.value = scrollBottom >= bodyEl.scrollHeight - 8
}

async function openPolicyModal() {
  policyModalOpen.value = true
  policyReadyToConfirm.value = false

  await nextTick()
  if (!policyBodyRef.value) return

  policyBodyRef.value.scrollTop = 0
  updatePolicyReadiness()
}

function closePolicyModal() {
  policyModalOpen.value = false
  policyReadyToConfirm.value = false
}

function handlePolicyScroll() {
  updatePolicyReadiness()
}

function markPolicyAsRead() {
  if (!policyReadyToConfirm.value) return

  policiesRead.value = true
  // "Read both documents first" is no longer true once they have been.
  clearFieldError('agreed')
  closePolicyModal()
}

// The box cannot be ticked before the documents are read. A click on it then
// opens them, rather than landing on a greyed-out control that does nothing.
function handleAgreeClick(event) {
  if (policiesRead.value) return

  event.preventDefault()
  openPolicyModal()
}

async function loadPublicLegalSettings() {
  legalLoading.value = true
  legalError.value = ''

  try {
    const { data } = await settingsApi.getPublicLegal()
    const settings = data?.settings ?? {}

    legalTexts.terms_of_use_text = String(settings.terms_of_use_text || defaultTermsOfUseText)
    legalTexts.privacy_policy_text = String(settings.privacy_policy_text || defaultPrivacyPolicyText)
    legalUpdatedAt.value = String(data?.last_updated_at ?? '')
  } catch (error) {
    legalTexts.terms_of_use_text = defaultTermsOfUseText
    legalTexts.privacy_policy_text = defaultPrivacyPolicyText
    legalUpdatedAt.value = ''
    legalError.value = 'Unable to load latest policy text. Showing default content.'
  } finally {
    legalLoading.value = false
    if (policyModalOpen.value) {
      await nextTick()
      updatePolicyReadiness()
    }
  }
}

onMounted(() => {
  loadPublicLegalSettings()
})

async function handleSubmit() {
  apiError.value = ''
  resetFieldErrors()

  const firstName = form.first_name.trim()
  const middleName = form.middle_name.trim()
  const lastName = form.last_name.trim()
  const studentId = form.student_id.trim()
  const yearLevel = Number(form.year_level)
  const email = form.email.trim()

  if (!firstName) {
    fieldErrors.first_name = 'Enter your first name.'
  }

  if (!lastName) {
    fieldErrors.last_name = 'Enter your last name.'
  }

  if (!studentId) {
    fieldErrors.student_id = 'Enter your student ID.'
  } else if (!/^\d{7,10}$/.test(studentId)) {
    // Says both the rule and what was actually typed, because "invalid
    // format" leaves someone staring at a number that looks fine to them.
    fieldErrors.student_id = `Student ID must be 7 to 10 digits -- you entered ${studentId.length}.`
  }

  if (!form.year_level) {
    fieldErrors.year_level = 'Select your year level.'
  } else if (![1, 2, 3, 4].includes(yearLevel)) {
    fieldErrors.year_level = 'Select a valid year level.'
  }

  if (!email) {
    fieldErrors.email = 'Enter your email address.'
  }

  if (!form.password) {
    fieldErrors.password = 'Choose a password.'
  } else {
    const passwordProblem = firstPasswordProblem(form.password)

    if (passwordProblem) {
      fieldErrors.password = passwordProblem
    }
  }

  if (!form.password_confirmation) {
    fieldErrors.password_confirmation = 'Re-enter your password.'
  } else if (pwMismatch.value) {
    fieldErrors.password_confirmation = 'Passwords do not match.'
  }

  if (!policiesRead.value) {
    fieldErrors.agreed = 'Read the Terms of Service and Privacy Policy, then tick the box to agree.'
  } else if (!form.agreed) {
    fieldErrors.agreed = 'Tick the box to agree before creating your account.'
  }

  if (hasFieldErrors.value) {
    apiError.value = 'Check the highlighted fields and try again.'
    focusFirstError()
    return
  }

  isLoading.value = true
  try {
    const result = await auth.register(
      firstName,
      middleName,
      lastName,
      studentId,
      yearLevel,
      email,
      form.password,
      form.password_confirmation,
      form.agreed,
    )

    // Registration no longer signs anyone in -- the account is inert until the
    // emailed link is opened, so send them to their inbox, not the dashboard.
    pendingEmail.value = result?.email ?? email
  } catch (error) {
    const payload = error.response?.data

    // Server-side rules the form cannot know about -- an address or student ID
    // already registered, a domain that is not the institution's -- come back
    // per field and are shown against that field, not as "Validation failed."
    if (applyServerErrors(payload?.errors)) {
      apiError.value = 'Check the highlighted fields and try again.'
      focusFirstError()
    } else {
      apiError.value = payload?.message ?? 'Registration failed. Please try again.'
    }
  } finally {
    isLoading.value = false
  }
}
</script>

<style scoped>
/* Shared field, alert and layout styles live in auth.css (see AuthLayout.vue). */

.pw-strength {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.pw-track {
  flex: 1;
  height: 5px;
  border-radius: 999px;
  background: var(--lnu-gray);
  overflow: hidden;
}

.pw-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.25s ease;
}

.pw-label {
  font-size: 13px;
  font-weight: 600;
}

.pw-strength .pw-rules {
  flex-basis: 100%;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  column-gap: 14px;
}

.terms-section {
  display: grid;
  gap: 8px;
}

.terms-wrap {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  font-size: 13.5px;
  color: var(--lnu-text-muted);
}

.terms-wrap input {
  margin-top: 3px;
  accent-color: var(--lnu-navy);
}

.terms-wrap input[aria-invalid='true'] {
  outline: 2px solid var(--lnu-danger);
  outline-offset: 2px;
  border-radius: 3px;
}

@media (pointer: coarse) {
  .terms-wrap {
    align-items: center;
    min-height: 44px;
  }

  .terms-wrap input {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    margin-top: 0;
  }
}

.terms-helper {
  margin: 0;
  font-size: 12.5px;
  line-height: 1.4;
  color: var(--lnu-text-muted);
}

/* The policy dialog is teleported to <body>, outside .auth-shell, so it
   is styled here rather than through auth.css. */
.policy-modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  justify-content: center;
  align-items: center;
  background: rgba(10, 18, 56, 0.58);
  padding: 20px;
}

/* dvh after vh: on a phone 90vh is measured without the browser's toolbar,
   so the dialog's last row, the button, could sit underneath it. */
.policy-modal {
  width: min(780px, 100%);
  max-height: min(640px, 90vh);
  max-height: min(640px, 90dvh);
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  background: #fff;
  border-radius: 14px;
  border: 1px solid rgba(13, 21, 71, 0.22);
  overflow: hidden;
}

.policy-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px 18px;
  border-bottom: 1px solid rgba(13, 21, 71, 0.12);
}

.policy-modal-header h3 {
  margin: 0;
  color: var(--lnu-text);
}

.policy-close-btn {
  border: 1px solid rgba(13, 21, 71, 0.2);
  background: #fff;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--lnu-navy);
  padding: 6px 10px;
  cursor: pointer;
}

.policy-close-btn:hover {
  border-color: var(--lnu-navy);
}

/* contain: the reader is asked to scroll to the end, and once there the
   next swipe used to move the registration form behind the dialog. */
.policy-modal-body {
  overflow: auto;
  overscroll-behavior: contain;
  padding: 16px 18px;
  display: grid;
  gap: 14px;
}

.policy-updated,
.policy-loading {
  margin: 0;
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.policy-warning {
  margin: 0;
  font-size: 13px;
  color: var(--lnu-danger);
}

.policy-article h4 {
  margin: 0 0 6px;
  color: var(--lnu-text);
  font-size: 15px;
}

/* Two documents share the dialog now, so the second needs a visible start. */
.policy-article + .policy-article {
  border-top: 1px solid rgba(13, 21, 71, 0.12);
  padding-top: 14px;
}

.policy-body-text {
  margin: 0;
  color: var(--lnu-text-muted);
  line-height: 1.52;
  font-size: 14px;
  white-space: pre-line;
}

.policy-modal-footer {
  border-top: 1px solid rgba(13, 21, 71, 0.12);
  padding: 14px 18px;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
  align-items: center;
}

.policy-scroll-note {
  font-size: 13px;
  color: var(--lnu-text-muted);
}

.policy-mark-btn {
  min-width: 132px;
  height: 38px;
  padding: 0 16px;
}

@media (max-width: 560px) {
  .pw-strength .pw-rules {
    grid-template-columns: minmax(0, 1fr);
  }

  .policy-modal-backdrop {
    padding: 12px;
  }

  /* Two documents to read on a small screen: the dialog takes all of it. */
  .policy-modal {
    max-height: calc(100vh - 24px);
    max-height: calc(100dvh - 24px);
  }

  .policy-modal-header h3 {
    font-size: 16px;
    line-height: 1.3;
  }

  .policy-modal-footer {
    align-items: stretch;
  }

  .policy-mark-btn {
    width: 100%;
  }
}

@media (pointer: coarse) {
  .policy-close-btn {
    flex-shrink: 0;
    min-height: 44px;
    padding: 0 14px;
  }

  .policy-mark-btn {
    height: 44px;
  }
}
</style>
