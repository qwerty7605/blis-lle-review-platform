// Light or dark. The choice is 'light', 'dark' or 'system' (follow the
// device). It is kept on this device (localStorage, read again by
// public/theme-boot.js before the page draws, so a dark page never flashes
// white) and with the account (preferences.theme), so it follows the person
// to another device once they sign in there.
import { ref } from 'vue'

export const THEME_CHOICES = ['light', 'dark', 'system']
const STORAGE_KEY = 'blis-theme'

const deviceDark = typeof window !== 'undefined' && window.matchMedia
  ? window.matchMedia('(prefers-color-scheme: dark)')
  : null

function stored() {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return THEME_CHOICES.includes(value) ? value : 'light'
  } catch {
    return 'light'
  }
}

/** What the person picked. */
export const themeChoice = ref(stored())

/** What is on screen now: 'light' or 'dark'. Charts read this to pick their colours. */
export const activeTheme = ref('light')

function apply() {
  const dark = themeChoice.value === 'dark' || (themeChoice.value === 'system' && Boolean(deviceDark?.matches))
  activeTheme.value = dark ? 'dark' : 'light'
  document.documentElement.dataset.theme = activeTheme.value
}

export function initTheme() {
  apply()
  deviceDark?.addEventListener?.('change', apply)
}

export function setThemeChoice(choice) {
  if (!THEME_CHOICES.includes(choice)) return
  themeChoice.value = choice
  try {
    localStorage.setItem(STORAGE_KEY, choice)
  } catch {
    // Private windows may refuse storage; the theme still applies to this page.
  }
  apply()
}
