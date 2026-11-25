// The in-app guide: a short tour of the page someone is on, highlighting one
// part at a time with a note on what it is for (driver.js draws it).
//
// - The first time someone opens a page that has a guide, it starts by
//   itself; the very first one also opens with the welcome steps.
// - The "?" button in the top bar replays the current page's guide at any time.
// - Settings has "Show the guides again", which starts them all over.
//
// Which guides someone has finished or skipped is kept with their account
// (preferences.guides_seen), so a guide seen on a laptop is not shown again
// on their phone. The steps themselves live in ./guideSteps.js.
import { driver } from 'driver.js'
import 'driver.js/dist/driver.css'
import './guide.css'
import { ref } from 'vue'
import { accountApi } from '@/api/account.api'
import { useAuthStore } from '@/store/auth.store'
import { COMPACT_QUERY } from '@/views/dashboard/composables/useViewport'
import { guideFor, welcomeSteps } from './guideSteps'

export const WELCOME_KEY = 'welcome'

/** True while a guide is on screen. */
export const guideActive = ref(false)

let current = null
// Guides finished in this tab, in case saving them to the account fails.
const seenHere = new Set()

/** 'student', 'teacher' or 'admin': the set of guides someone gets. */
export function guideRole(role) {
  const value = String(role ?? '').toLowerCase()
  if (value === 'admin') return 'admin'
  if (value === 'student') return 'student'
  return 'teacher'
}

function seenList(auth) {
  const saved = auth.user?.preferences?.guides_seen
  return [...new Set([...(Array.isArray(saved) ? saved : []), ...seenHere])]
}

function isVisible(el) {
  if (!el) return false
  const box = el.getBoundingClientRect()
  return box.width > 0 && box.height > 0 && getComputedStyle(el).visibility !== 'hidden'
}

/** The first element on screen matching a selector (a page can render one twice). */
function firstVisible(selector) {
  return [...document.querySelectorAll(selector)].find(isVisible) ?? null
}

// Something else is using the screen: a dialog, the exam itself, the phone's
// navigation drawer. A guide waits rather than cover it.
function screenIsBusy() {
  return Boolean(document.querySelector('.modal-backdrop, .exam-attempt-backdrop, .layout-sidebar.is-open, .driver-active'))
}

function compactLayout() {
  return window.matchMedia(COMPACT_QUERY).matches
}

/** The steps to show now: those whose part of the page is on screen. */
function resolveSteps(steps) {
  const compact = compactLayout()

  return steps
    .filter((step) => !(compact && step.desktopOnly) && !(!compact && step.compactOnly))
    .map((step) => {
      if (!step.element) return { popover: { ...step.popover } }
      const el = firstVisible(step.element)
      return el ? { element: el, popover: { ...step.popover } } : null
    })
    .filter(Boolean)
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * Waits (up to `ms`) for a page to finish loading what its guide points at:
 * until every part is on screen, or the number found has held still for a
 * second (some parts appear only with data, such as a teacher filter).
 */
async function waitForPage(steps, ms) {
  const targets = steps.filter((step) => step.element)
  const deadline = Date.now() + ms
  let found = -1
  let stableSince = Date.now()

  while (Date.now() < deadline) {
    const now = targets.filter((step) => firstVisible(step.element)).length
    if (now === targets.length) return
    // A spinning refresh icon is how BLIS pages show they are still loading.
    if (firstVisible('.layout-content .spin-soft')) {
      found = -1
      await sleep(200)
      continue
    }
    if (now !== found) {
      found = now
      stableSince = Date.now()
    } else if (now > 0 && Date.now() - stableSince >= 1000) {
      return
    }
    await sleep(200)
  }
}

export function useGuide() {
  const auth = useAuthStore()

  async function remember(keys) {
    const list = [...new Set([...seenList(auth), ...keys])]
    keys.forEach((key) => seenHere.add(key))

    try {
      const { data } = await accountApi.savePreferences({ guides_seen: list })
      auth.user = { ...auth.user, ...data.user }
    } catch {
      // Kept for this tab; the guide is offered again on the next sign-in.
    }
  }

  function hasSeen(key) {
    return seenList(auth).includes(key)
  }

  function hasGuide(routeName) {
    return guideFor(guideRole(auth.user?.role), routeName) !== null
  }

  /**
   * Shows the guide of a page. `withWelcome` puts the welcome steps first.
   * Returns false when there was nothing to show.
   */
  async function start(routeName, { withWelcome = false, waitMs = 2500 } = {}) {
    const role = guideRole(auth.user?.role)
    const guide = guideFor(role, routeName)
    const pageSteps = guide?.steps ?? []
    const all = [...(withWelcome ? welcomeSteps(role) : []), ...pageSteps]

    if (all.length === 0) return false
    if (waitMs > 0) await waitForPage(pageSteps, waitMs)

    const steps = resolveSteps(all)
    if (steps.length === 0) return false

    current?.destroy()
    const keys = [...(guide ? [`${role}:${routeName}`] : []), ...(withWelcome ? [WELCOME_KEY] : [])]

    current = driver({
      steps,
      showProgress: steps.length > 1,
      progressText: '{{current}} of {{total}}',
      nextBtnText: 'Next',
      prevBtnText: 'Back',
      doneBtnText: 'Got it',
      allowClose: true,
      overlayClickBehavior: 'none',
      stagePadding: 6,
      stageRadius: 14,
      smoothScroll: true,
      popoverClass: 'blis-guide',
      overlayColor: '#0d1547',
      overlayOpacity: 0.55,
      onPopoverRender: (popover, { state }) => {
        // Nothing to go back to on the first step.
        if (state.activeIndex === 0) popover.previousButton.style.display = 'none'
        // A "Skip" link on every step but the last, so nobody is held in it.
        if (state.activeIndex === steps.length - 1) return
        const skip = document.createElement('button')
        skip.type = 'button'
        skip.className = 'blis-guide-skip'
        skip.textContent = 'Skip guide'
        skip.addEventListener('click', () => current?.destroy())
        popover.footer.prepend(skip)
      },
      onDestroyed: () => {
        guideActive.value = false
        current = null
        remember(keys)
      },
    })

    guideActive.value = true
    current.drive()
    return true
  }

  /**
   * Starts a page's guide by itself the first time someone opens that page,
   * once its content has loaded and nothing else is on screen.
   */
  async function offer(routeName) {
    const role = guideRole(auth.user?.role)
    const firstEver = !hasSeen(WELCOME_KEY)
    const pageKey = `${role}:${routeName}`
    const pageNew = hasGuide(routeName) && !hasSeen(pageKey)

    if (!firstEver && !pageNew) return

    // Let the page settle: data loads, the layout measures itself.
    await sleep(900)
    for (let i = 0; i < 20 && screenIsBusy(); i++) await sleep(500)
    if (screenIsBusy() || current) return

    await start(routeName, { withWelcome: firstEver, waitMs: 10000 })
  }

  /** Every guide is shown again, starting with the welcome, as on a first sign-in. */
  async function resetAll() {
    seenHere.clear()
    const { data } = await accountApi.savePreferences({ guides_seen: [] })
    auth.user = { ...auth.user, ...data.user }
  }

  return { start, offer, hasGuide, resetAll }
}

/** Closes any guide on screen (signing out, leaving the page). */
export function closeGuide() {
  current?.destroy()
}
