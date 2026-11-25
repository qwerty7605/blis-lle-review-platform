import { computed, onMounted, reactive, ref } from 'vue'
import { useDashboardDataServices } from './useDashboardDataServices'
import { early, lengthProblem, requiredProblem, useFieldChecks, wholeNumberProblem } from '@/utils/fieldChecks'
import { DEFAULT_MARK, MAX_MARK, MIN_MARK, STAFF_MARK, markOf, toGrade, trimmedNumber } from '@/utils/passingMark'

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
  // The mark as last loaded or saved, to say what saving a new one does.
  const savedPassingMark = ref(DEFAULT_MARK)
  const settingsForm = reactive({
    first_semester_start_month: 8,
    second_semester_start_month: 1,
    students_passing_mark: DEFAULT_MARK,
    allow_public_registration: true,
    announcement_banner: '',
    terms_of_use_text: '',
    privacy_policy_text: '',
  })

  // SettingsController::updateSystem.
  const settingsChecks = useFieldChecks('settings', {
    // Shown as soon as the two match: another pick is the only fix.
    second_semester_start_month: () => (Number(settingsForm.first_semester_start_month) === Number(settingsForm.second_semester_start_month)
      ? early('The 2nd semester cannot start in the same month as the 1st.')
      : ''),
    students_passing_mark: () => wholeNumberProblem(settingsForm.students_passing_mark, { label: 'The passing mark', min: MIN_MARK, max: MAX_MARK }),
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
      settingsForm.first_semester_start_month = Number(settings.first_semester_start_month) || 8
      settingsForm.second_semester_start_month = Number(settings.second_semester_start_month) || 1
      currentTerm.value = data.current_term?.label ?? ''
      settingsForm.students_passing_mark = markOf(settings.students_passing_mark)
      savedPassingMark.value = settingsForm.students_passing_mark
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
        first_semester_start_month: Number(settingsForm.first_semester_start_month),
        second_semester_start_month: Number(settingsForm.second_semester_start_month),
        students_passing_mark: Number(settingsForm.students_passing_mark),
        allow_public_registration: settingsForm.allow_public_registration,
        announcement_banner: settingsForm.announcement_banner.trim(),
        terms_of_use_text: termsOfUse,
        privacy_policy_text: privacyPolicy,
      })
      settingsMessage.value = 'System settings updated.'
      savedPassingMark.value = Number(settingsForm.students_passing_mark)
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

  // What the mark does, worked out as the administrator types it.
  const passingMarkPreview = computed(() => {
    const value = Number(settingsForm.students_passing_mark)
    if (!Number.isInteger(value) || value < MIN_MARK || value > MAX_MARK) return ''
    if (value === STAFF_MARK) return `Students need ${value}% to pass, and teachers see the same scores.`

    const below = value - 1
    const above = Math.round((value + 100) / 2)
    return `Students need ${value}% to pass. Teachers see grades where ${value}% is ${STAFF_MARK}: `
      + `a score of ${below}% is grade ${trimmedNumber(toGrade(below, value))}, and ${above}% is grade ${trimmedNumber(toGrade(above, value))}.`
  })

  const passingMarkChanged = computed(() => (
    Number(settingsForm.students_passing_mark) !== Number(savedPassingMark.value)
    && passingMarkPreview.value !== ''
  ))

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
    passingMarkPreview,
    passingMarkChanged,
    settingsChecks,
    loadSystemSettings,
    saveSystemSettings,
  }
}
