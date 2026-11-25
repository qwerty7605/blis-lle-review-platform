<template>
  <div class="account-settings">
    <!-- Name -->
    <article class="surface-card account-card">
      <header class="account-card-head">
        <h3>
          Your Name
          <InfoTip
            :text="isStudent
              ? 'Shown on class lists, exam results and reports. Your student ID, year level and email are kept by the school; ask an administrator if one of them is wrong.'
              : 'Shown on class lists, exam results and reports.'"
          />
        </h3>
      </header>

      <form class="account-form" novalidate @submit.prevent="saveProfile">
        <div class="account-grid three">
          <label class="field-stack">
            <span class="field-label">First Name</span>
            <input v-model="profile.first_name" type="text" class="text-input" autocomplete="given-name" maxlength="255" v-bind="profileChecks.bind('first_name')" />
            <FieldError :checks="profileChecks" field="first_name" />
          </label>

          <label class="field-stack">
            <span class="field-label">Middle Name <em>(optional)</em></span>
            <input v-model="profile.middle_name" type="text" class="text-input" autocomplete="additional-name" maxlength="255" />
          </label>

          <label class="field-stack">
            <span class="field-label">Last Name</span>
            <input v-model="profile.last_name" type="text" class="text-input" autocomplete="family-name" maxlength="255" v-bind="profileChecks.bind('last_name')" />
            <FieldError :checks="profileChecks" field="last_name" />
          </label>
        </div>

        <!-- What only an administrator changes, shown so nobody goes looking
             for where to edit it. -->
        <dl v-if="isStudent" class="account-facts">
          <div>
            <dt>Student ID</dt>
            <dd>{{ auth.user?.student_id || 'Not set' }}</dd>
          </div>
          <div>
            <dt>Year Level</dt>
            <dd>{{ yearLevelLabel }}</dd>
          </div>
          <div class="account-fact-wide">
            <dt>Email</dt>
            <dd>{{ auth.user?.email }}</dd>
          </div>
        </dl>

        <div v-if="profileMessage" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ profileMessage }}</span></div>
        <div v-if="profileError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ profileError }}</span></div>

        <div class="account-actions">
          <button type="submit" class="primary-btn" :disabled="profileSaving || !profileChanged">
            <RefreshCw v-if="profileSaving" :size="14" class="spin-soft" />
            <span>{{ profileSaving ? 'Saving...' : 'Save Name' }}</span>
          </button>
        </div>
      </form>
    </article>

    <!-- Email: staff and administrators -->
    <article v-if="!isStudent" class="surface-card account-card">
      <header class="account-card-head">
        <h3>Sign-In Email <InfoTip text="To change it we send a link to the new address; nothing changes until you open it." /></h3>
        <p>You sign in with <strong>{{ auth.user?.email }}</strong>.</p>
      </header>

      <form class="account-form" novalidate @submit.prevent="requestEmailChange">
        <div class="account-grid two">
          <label class="field-stack">
            <span class="field-label">New Email Address</span>
            <input v-model.trim="email.email" type="email" class="text-input" autocomplete="email" maxlength="255" v-bind="emailChecks.bind('email')" />
            <FieldError :checks="emailChecks" field="email" />
          </label>

          <label class="field-stack">
            <span class="field-label">Current Password</span>
            <input v-model="email.current_password" type="password" class="text-input" autocomplete="current-password" v-bind="emailChecks.bind('current_password')" />
            <FieldError :checks="emailChecks" field="current_password" />
          </label>
        </div>

        <div v-if="emailMessage" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ emailMessage }}</span></div>
        <div v-if="emailError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ emailError }}</span></div>

        <div class="account-actions">
          <button type="submit" class="primary-btn" :disabled="emailSaving">
            <RefreshCw v-if="emailSaving" :size="14" class="spin-soft" />
            <span>{{ emailSaving ? 'Sending...' : 'Send Confirmation Link' }}</span>
          </button>
        </div>
      </form>
    </article>

    <!-- Password -->
    <article class="surface-card account-card">
      <header class="account-card-head">
        <h3>Password</h3>
        <p>Changing it signs you out on every other device. You stay signed in here.</p>
      </header>

      <form class="account-form" novalidate @submit.prevent="changePassword">
        <div class="account-grid three">
          <label class="field-stack">
            <span class="field-label">Current Password</span>
            <input v-model="password.current_password" :type="showPasswords ? 'text' : 'password'" class="text-input" autocomplete="current-password" v-bind="passwordChecks.bind('current_password')" />
            <FieldError :checks="passwordChecks" field="current_password" />
          </label>

          <label class="field-stack">
            <span class="field-label">New Password</span>
            <input v-model="password.password" :type="showPasswords ? 'text' : 'password'" class="text-input" autocomplete="new-password" v-bind="passwordChecks.bind('password')" />
            <FieldError :checks="passwordChecks" field="password" />
          </label>

          <label class="field-stack">
            <span class="field-label">Confirm New Password</span>
            <input v-model="password.password_confirmation" :type="showPasswords ? 'text' : 'password'" class="text-input" autocomplete="new-password" v-bind="passwordChecks.bind('password_confirmation')" />
            <FieldError :checks="passwordChecks" field="password_confirmation" />
          </label>
        </div>

        <label class="check-item account-show">
          <input v-model="showPasswords" type="checkbox" />
          <span>Show passwords</span>
        </label>

        <ul v-if="password.password" class="account-rules" aria-label="Password requirements">
          <li v-for="rule in passwordRules" :key="rule.key" :class="{ met: rule.met }">
            <CheckCircle2 v-if="rule.met" :size="14" />
            <Circle v-else :size="14" />
            <span>{{ rule.label }}</span>
          </li>
        </ul>

        <div v-if="passwordMessage" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ passwordMessage }}</span></div>
        <div v-if="passwordError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ passwordError }}</span></div>

        <div class="account-actions">
          <button
            type="submit"
            class="primary-btn"
            :disabled="passwordSaving"
          >
            <RefreshCw v-if="passwordSaving" :size="14" class="spin-soft" />
            <span>{{ passwordSaving ? 'Saving...' : 'Change Password' }}</span>
          </button>
        </div>
      </form>
    </article>
    <!-- Appearance -->
    <article class="surface-card account-card">
      <header class="account-card-head">
        <h3>Appearance <InfoTip text="Saved with your account, so it follows you to any device you sign in on." /></h3>
      </header>

      <div class="account-theme" role="radiogroup" aria-label="Theme">
        <label v-for="option in themeOptions" :key="option.value" class="account-theme-option" :class="{ active: themeChoice === option.value }">
          <input type="radio" name="theme" :value="option.value" :checked="themeChoice === option.value" @change="chooseTheme(option.value)" />
          <component :is="option.icon" :size="16" />
          <span>{{ option.label }}</span>
        </label>
      </div>

      <div v-if="themeError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ themeError }}</span></div>
    </article>

    <!-- Guides -->
    <article class="surface-card account-card" data-guide="guides">
      <header class="account-card-head">
        <h3>Guides</h3>
      </header>

      <p class="account-note">
        Each page has a short guide that shows what its parts are for. It appears the first time you open the page.
        Press <b>Guide</b> at the top of any page to see it again, or show every guide again from the start here.
      </p>

      <button type="button" class="ghost-btn account-guides-btn" :disabled="guidesResetting" @click="showGuidesAgain">
        <RotateCcw :size="15" />
        <span>{{ guidesResetting ? 'Resetting...' : 'Show the Guides Again' }}</span>
      </button>

      <div v-if="guidesMessage" class="feedback success" role="status"><CheckCircle2 :size="15" /><span>{{ guidesMessage }}</span></div>
      <div v-if="guidesError" class="feedback danger" role="alert"><AlertCircle :size="15" /><span>{{ guidesError }}</span></div>
    </article>
  </div>
</template>

<script setup>
import FieldError from '@/components/FieldError.vue'
import InfoTip from '@/components/InfoTip.vue'
import { computed, reactive, ref, watch } from 'vue'
import { AlertCircle, CheckCircle2, Circle, Monitor, Moon, RefreshCw, RotateCcw, Sun } from 'lucide-vue-next'
import { accountApi } from '@/api/account.api'
import { useAuthStore } from '@/store/auth.store'
import { passwordChecklist } from '@/utils/password'
import {
  confirmationProblem,
  emailProblem,
  passwordProblem,
  problemMessage,
  requiredProblem,
  useFieldChecks,
} from '@/utils/fieldChecks'
import { setThemeChoice, themeChoice } from '@/utils/theme'
import { useGuide } from '@/guides/useGuide'

const auth = useAuthStore()

const role = computed(() => String(auth.user?.role ?? 'student').toLowerCase())
const isStudent = computed(() => role.value === 'student')
const isAdmin = computed(() => role.value === 'admin')

const yearLevelLabel = computed(() => {
  const level = Number(auth.user?.year_level)
  if (!level) return 'Not set'

  return `${level}${['th', 'st', 'nd', 'rd'][level] ?? 'th'} Year`
})

// A refusal the form could not see coming: under its field when the server
// named one (checks.takeServerErrors), otherwise above the button.
function generalError(error, fallback, checks = null) {
  if (checks?.takeServerErrors(error)) return ''
  if (Object.keys(error?.response?.data?.errors ?? {}).length > 0) return Object.values(error.response.data.errors).flat()[0] ?? fallback

  return error?.response?.data?.message ?? fallback
}

// ---------------------------------------------------------------- name
const profile = reactive({ first_name: '', middle_name: '', last_name: '' })
const profileError = ref('')
const profileMessage = ref('')
const profileSaving = ref(false)

// An account made before names were kept in parts has only the full name.
function fillProfile(user) {
  profile.first_name = user?.first_name ?? user?.name ?? ''
  profile.middle_name = user?.middle_name ?? ''
  profile.last_name = user?.last_name ?? ''
}

watch(() => auth.user?.id, () => fillProfile(auth.user), { immediate: true })

// AccountController::updateProfile: an administrator may go by one name.
const profileChecks = useFieldChecks('account-name', {
  first_name: () => requiredProblem(profile.first_name, 'Enter your first name.'),
  last_name: () => (isAdmin.value ? '' : requiredProblem(profile.last_name, 'Enter your last name.')),
})

const profileChanged = computed(() => (
  profile.first_name.trim() !== (auth.user?.first_name ?? auth.user?.name ?? '')
  || profile.middle_name.trim() !== (auth.user?.middle_name ?? '')
  || profile.last_name.trim() !== (auth.user?.last_name ?? '')
))

async function saveProfile() {
  profileError.value = ''
  profileMessage.value = ''

  if (!profileChecks.check()) return

  profileSaving.value = true

  try {
    const { data } = await accountApi.updateProfile({
      first_name: profile.first_name.trim(),
      middle_name: profile.middle_name.trim() || null,
      last_name: profile.last_name.trim() || null,
    })

    // The sidebar and every page read the name from here.
    auth.user = { ...auth.user, ...data.user }
    fillProfile(auth.user)
    profileChecks.reset()
    profileMessage.value = 'Your name has been updated.'
  } catch (error) {
    profileError.value = generalError(error, 'Unable to save your name.', profileChecks)
  } finally {
    profileSaving.value = false
  }
}

// --------------------------------------------------------------- email
const email = reactive({ email: '', current_password: '' })
const emailError = ref('')
const emailMessage = ref('')
const emailSaving = ref(false)

const emailChecks = useFieldChecks('account-email', {
  email: () => {
    const problem = emailProblem(email.email, { emptyMessage: 'Enter the new email address.' })

    if (problemMessage(problem)) return problem

    return email.email.trim().toLowerCase() === String(auth.user?.email ?? '').toLowerCase()
      ? 'That is already your sign-in email.'
      : ''
  },
  current_password: () => requiredProblem(email.current_password, 'Enter your current password.'),
})

async function requestEmailChange() {
  emailError.value = ''
  emailMessage.value = ''

  if (!emailChecks.check()) return

  emailSaving.value = true

  try {
    const { data } = await accountApi.requestEmailChange({ ...email })

    emailMessage.value = data?.message ?? 'Check the new address for a confirmation link.'
    email.email = ''
    email.current_password = ''
    emailChecks.reset()
  } catch (error) {
    emailError.value = error?.response?.status === 429
      ? 'Too many requests for now. Try again in an hour.'
      : generalError(error, 'Unable to send the confirmation link.', emailChecks)
  } finally {
    emailSaving.value = false
  }
}

// ------------------------------------------------------------ password
const password = reactive({ current_password: '', password: '', password_confirmation: '' })
const passwordError = ref('')
const passwordMessage = ref('')
const passwordSaving = ref(false)
const showPasswords = ref(false)

const passwordRules = computed(() => passwordChecklist(password.password))

const passwordChecks = useFieldChecks('account-password', {
  current_password: () => requiredProblem(password.current_password, 'Enter your current password.'),
  password: () => {
    const problem = passwordProblem(password.password, { emptyMessage: 'Choose a new password.' })

    if (problemMessage(problem)) return problem

    return password.password === password.current_password ? 'Choose a password that is not your current one.' : ''
  },
  password_confirmation: () => confirmationProblem(password.password, password.password_confirmation),
})

async function changePassword() {
  passwordError.value = ''
  passwordMessage.value = ''

  if (!passwordChecks.check()) return

  passwordSaving.value = true

  try {
    const { data } = await accountApi.changePassword({ ...password })

    passwordMessage.value = data?.message ?? 'Password changed.'
    password.current_password = ''
    password.password = ''
    password.password_confirmation = ''
    showPasswords.value = false
    passwordChecks.reset()
  } catch (error) {
    passwordError.value = generalError(error, 'Unable to change your password.', passwordChecks)
  } finally {
    passwordSaving.value = false
  }
}

// ---------------------------------------------------------------- appearance
const themeOptions = [
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'dark', label: 'Dark', icon: Moon },
  { value: 'system', label: 'Match device', icon: Monitor },
]
const themeError = ref('')

// Applied at once; saved with the account in the background. If the save
// fails the choice still holds on this device.
async function chooseTheme(value) {
  themeError.value = ''
  setThemeChoice(value)

  try {
    const { data } = await accountApi.savePreferences({ theme: value })
    auth.user = { ...auth.user, ...data.user }
  } catch (error) {
    themeError.value = generalError(error, 'Applied here, but it could not be saved to your account. Try again.')
  }
}

// Every page's guide (and the welcome) is offered again, starting with this one.
const guide = useGuide()
const guidesResetting = ref(false)
const guidesMessage = ref('')
const guidesError = ref('')

async function showGuidesAgain() {
  guidesResetting.value = true
  guidesMessage.value = ''
  guidesError.value = ''

  try {
    await guide.resetAll()
    guidesMessage.value = 'Done. Each page will show its guide again the next time you open it.'
  } catch (error) {
    guidesError.value = generalError(error, 'The guides could not be reset. Try again.')
  } finally {
    guidesResetting.value = false
  }
}
</script>

<style scoped src="./accountSettings.css"></style>
