import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
// Poppins is served from this site, not Google Fonts: the production security
// policy (nginx/production.conf) only lets fonts and stylesheets load from our
// own address, so a Google Fonts link was blocked and every page fell back to
// another font. Latin covers English and Filipino names (ñ included).
import '@fontsource/poppins/latin-400.css'
import '@fontsource/poppins/latin-500.css'
import '@fontsource/poppins/latin-600.css'
import '@fontsource/poppins/latin-700.css'
import './style.css'
import './theme/dark-colors.css'
import App from './App.vue'
import router from './router'
import { setSessionEndedHandler } from './api/http'
import { useAuthStore } from './store/auth.store'
import { keepInStepWithUpdates } from './utils/appVersion'
import { watchForIdle } from './utils/idleSignOut'
import { initTheme, setThemeChoice } from './utils/theme'

// Before anything renders, so the first frame is already in the right theme.
initTheme()

const app = createApp(App)
app.use(createPinia())
app.use(router)

// A session that ends under an open page takes the reader to the sign-in
// page, where they are told why, instead of leaving them on a page whose
// every request now fails.
const SIGNED_OUT_PAGES = ['login', 'register', 'forgot-password', 'reset-password']

setSessionEndedHandler((reason) => {
  const auth = useAuthStore()
  if (!auth.isAuthenticated || auth.signingOut) return

  auth.forgetSession()

  if (SIGNED_OUT_PAGES.includes(String(router.currentRoute.value.name))) return

  router.replace({ name: 'login', query: { ended: reason === 'disabled' ? 'disabled' : '1' } })
})

// A sign-in made without "Remember me", left alone for too long.
watchForIdle(
  () => useAuthStore().user?.session_idle_minutes ?? null,
  async () => {
    const auth = useAuthStore()
    if (!auth.isAuthenticated || auth.signingOut) return

    await auth.logout()
    router.replace({ name: 'login', query: { ended: 'idle' } })
  },
)

keepInStepWithUpdates(router)

// The theme saved with the account wins once its owner is signed in, so the
// choice follows them from device to device.
watch(
  () => useAuthStore().user?.preferences?.theme ?? null,
  (theme) => {
    if (theme) setThemeChoice(theme)
  },
  { immediate: true },
)

app.mount('#app')
