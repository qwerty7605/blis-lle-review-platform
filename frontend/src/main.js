import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import { setSessionEndedHandler } from './api/http'
import { useAuthStore } from './store/auth.store'
import { keepInStepWithUpdates } from './utils/appVersion'
import { watchForIdle } from './utils/idleSignOut'

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

app.mount('#app')
