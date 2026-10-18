import { onMounted, reactive, ref } from 'vue'
import { useDashboardDataServices } from './useDashboardDataServices'

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
  const settingsForm = reactive({
    platform_name: '',
    academic_term: '',
    allow_public_registration: true,
    announcement_banner: '',
    terms_of_use_text: '',
    privacy_policy_text: '',
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
      settingsForm.academic_term = settings.academic_term ?? ''
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

    if (!termsOfUse || !privacyPolicy) {
      settingsError.value = 'Terms of Use and Privacy Policy are required.'
      return
    }

    settingsSaving.value = true

    try {
      await services.saveSystemSettings({
        platform_name: settingsForm.platform_name.trim(),
        academic_term: settingsForm.academic_term.trim(),
        allow_public_registration: settingsForm.allow_public_registration,
        announcement_banner: settingsForm.announcement_banner.trim(),
        terms_of_use_text: termsOfUse,
        privacy_policy_text: privacyPolicy,
      })
      settingsMessage.value = 'System settings updated.'
      // The banner above every page is read by the layout, which does not
      // know these were saved: tell it, so the admin sees the announcement
      // they just wrote without reloading.
      window.dispatchEvent(new Event(ANNOUNCEMENT_CHANGED_EVENT))
    } catch (error) {
      settingsError.value = firstApiError(error, 'Unable to save settings.')
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
    loadSystemSettings,
    saveSystemSettings,
  }
}
