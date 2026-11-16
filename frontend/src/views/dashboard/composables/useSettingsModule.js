import { onMounted, reactive, ref } from 'vue'
import { useDashboardDataServices } from './useDashboardDataServices'
import { early, lengthProblem, requiredProblem, useFieldChecks } from '@/utils/fieldChecks'

// The months a semester can start in, for the School Calendar pickers.
export const MONTH_OPTIONS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
].map((label, index) => ({ value: index + 1, label }))

function firstApiError(error, fallbackMessage) {
  const messages = Object.values(error?.response?.data?.errors ?? {}).flat()
  if (messages.length > 0) return String(messages[0])
  return error?.response?.data?.message ?? fallbackMessage
}

// Heard by DashboardLayout.vue.
export const ANNOUNCEMENT_CHANGED_EVENT = 'lle:announcement-changed'

// `enabled` says whether this reader is shown the platform's settings at all:
// a student may not even read them, and a teacher has nothing to do there.
export function useSettingsModule({ enabled = () => true } = {}) {
  const services = useDashboardDataServices()

  const settingsLoading = ref(false)
  const settingsSaving = ref(false)
  const settingsError = ref('')
  const settingsMessage = ref('')
  const settingsCanEdit = ref(false)
  // Today's term by the saved calendar ("1st Semester, AY 2026-2027"), as
  // the server works it out.
  const currentTerm = ref('')
  const settingsForm = reactive({
    platform_name: '',
    first_semester_start_month: 8,
    second_semester_start_month: 1,
    allow_public_registration: true,
    announcement_banner: '',
    terms_of_use_text: '',
    privacy_policy_text: '',
  })

  // SettingsController::updateSystem.
  const settingsChecks = useFieldChecks('settings', {
    platform_name: () => requiredProblem(settingsForm.platform_name, 'Enter the platform name.'),
    // Shown as soon as the two match: another pick is the only fix.
    second_semester_start_month: () => (Number(settingsForm.first_semester_start_month) === Number(settingsForm.second_semester_start_month)
      ? early('The 2nd semester cannot start in the same month as the 1st.')
      : ''),
    announcement_banner: () => lengthProblem(settingsForm.announcement_banner, { label: 'The announcement', max: 500 }),
    terms_of_use_text: () => requiredProblem(settingsForm.terms_of_use_text, 'The Terms of Use cannot be empty: students agree to them when they sign up.')
      || lengthProblem(settingsForm.terms_of_use_text, { label: 'The Terms of Use', max: 20000 }),
    privacy_policy_text: () => requiredProblem(settingsForm.privacy_policy_text, 'The Privacy Policy cannot be empty: students agree to it when they sign up.')
      || lengthProblem(settingsForm.privacy_policy_text, { label: 'The Privacy Policy', max: 20000 }),
  })

  async function loadSystemSettings() {
    settingsLoading.value = true
    settingsError.value = ''
    settingsMessage.value = ''

    try {
      const { data } = await services.getSystemSettings()
      const settings = data.settings ?? {}
      settingsCanEdit.value = Boolean(data.can_edit)
      settingsForm.platform_name = settings.platform_name ?? ''
      settingsForm.first_semester_start_month = Number(settings.first_semester_start_month) || 8
      settingsForm.second_semester_start_month = Number(settings.second_semester_start_month) || 1
      currentTerm.value = data.current_term?.label ?? ''
      settingsForm.allow_public_registration = Boolean(settings.allow_public_registration)
      settingsForm.announcement_banner = settings.announcement_banner ?? ''
      settingsForm.terms_of_use_text = settings.terms_of_use_text ?? ''
      settingsForm.privacy_policy_text = settings.privacy_policy_text ?? ''
    } catch (error) {
      settingsError.value = firstApiError(error, 'Unable to load system settings.')
    } finally {
      settingsLoading.value = false
    }
  }

  async function saveSystemSettings() {
    if (!settingsCanEdit.value) return

    const termsOfUse = settingsForm.terms_of_use_text.trim()
    const privacyPolicy = settingsForm.privacy_policy_text.trim()

    settingsError.value = ''
    settingsMessage.value = ''

    if (!settingsChecks.check()) return

    settingsSaving.value = true

    try {
      const { data } = await services.saveSystemSettings({
        platform_name: settingsForm.platform_name.trim(),
        first_semester_start_month: Number(settingsForm.first_semester_start_month),
        second_semester_start_month: Number(settingsForm.second_semester_start_month),
        allow_public_registration: settingsForm.allow_public_registration,
        announcement_banner: settingsForm.announcement_banner.trim(),
        terms_of_use_text: termsOfUse,
        privacy_policy_text: privacyPolicy,
      })
      settingsMessage.value = 'System settings updated.'
      if (data?.current_term?.label) currentTerm.value = data.current_term.label
      // The banner above every page is read by the layout, which does not
      // know these were saved: tell it, so the admin sees the announcement
      // they just wrote without reloading.
      window.dispatchEvent(new Event(ANNOUNCEMENT_CHANGED_EVENT))
    } catch (error) {
      if (!settingsChecks.takeServerErrors(error)) settingsError.value = firstApiError(error, 'Unable to save settings.')
    } finally {
      settingsSaving.value = false
    }
  }

  onMounted(async () => {
    if (enabled()) await loadSystemSettings()
  })

  return {
    settingsLoading,
    settingsSaving,
    settingsError,
    settingsMessage,
    settingsCanEdit,
    settingsForm,
    currentTerm,
    settingsChecks,
    loadSystemSettings,
    saveSystemSettings,
  }
}
