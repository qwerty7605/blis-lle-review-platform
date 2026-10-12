// Keeps a page that has been left open in step with the site as it is now.
//
// A page keeps running the code it loaded. Once the site has been updated
// that code is old, and what it would fetch next is gone: each page's script
// is loaded the first time the page is opened, under a name that changes with
// every update, and the update removes the old files. So a tab opened before
// an update did nothing when a menu item was clicked, until it was reloaded
// by hand.
//
// Two answers. Both end with the browser loading the address the reader was
// going to from scratch, which brings the new code with it.
//
//   Ahead of time: every few minutes, and when the reader comes back to the
//   tab, the site's index.html is read and compared with the one this page
//   was started from. If it names a different script there has been an
//   update, and the next move to another page is a full load.
//
//   After the fact: if a page's script fails to load all the same, the full
//   load happens there and then.
//
// None of this runs in development, where there is no built index.html.

const CHECK_EVERY_MS = 5 * 60 * 1000
const CHECK_ON_RETURN_AFTER_MS = 60 * 1000
// A full load that leads straight to another one would be a loop. One is
// allowed, then nothing for this long.
const RELOAD_GUARD_KEY = 'lle.loadedFreshAt'
const RELOAD_GUARD_MS = 15 * 1000

const ENTRY_SCRIPT = /<script[^>]+type="module"[^>]+src="([^"]*\/assets\/[^"]+\.js)"/i

let updateWaiting = false
let lastCheckAt = 0
let checking = false

function pathOf(url) {
  try {
    return new URL(url, window.location.origin).pathname
  } catch {
    return ''
  }
}

// The script this page was started from, or '' in development.
function runningEntry() {
  const script = document.querySelector('script[type="module"][src*="/assets/"]')

  return script ? pathOf(script.getAttribute('src')) : ''
}

async function checkForUpdate() {
  const running = runningEntry()
  if (!running || updateWaiting || checking) return
  if (navigator.onLine === false) return

  checking = true
  lastCheckAt = Date.now()

  try {
    const response = await fetch('/index.html', { cache: 'no-store', credentials: 'omit' })
    if (!response.ok) return

    const match = ENTRY_SCRIPT.exec(await response.text())
    const served = match ? pathOf(match[1]) : ''

    if (served && served !== running) updateWaiting = true
  } catch {
    // Offline, or the server is restarting mid-update: ask again later.
  } finally {
    checking = false
  }
}

function recentlyLoadedFresh() {
  try {
    return Date.now() - Number(sessionStorage.getItem(RELOAD_GUARD_KEY) ?? 0) < RELOAD_GUARD_MS
  } catch {
    return false
  }
}

// Returns whether the browser was sent to load `href` from scratch.
function loadFresh(href) {
  if (navigator.onLine === false || recentlyLoadedFresh()) return false

  try {
    sessionStorage.setItem(RELOAD_GUARD_KEY, String(Date.now()))
  } catch {
    // Without storage there is no guard; one load is still the right thing.
  }

  window.location.assign(href)

  return true
}

// What browsers say when a page's script (or its stylesheet) cannot be had.
function isMissingScript(error) {
  const message = String(error?.message ?? error ?? '')

  return /dynamically imported module|importing a module script failed|unable to preload css|failed to fetch/i.test(message)
}

export function keepInStepWithUpdates(router) {
  if (typeof window === 'undefined') return

  router.beforeEach((to, from) => {
    // Nothing to leave behind on the first page; and a move within a page
    // (a filter in the address, a room picked) keeps the page it is on.
    if (!updateWaiting || from.matched.length === 0 || to.path === from.path) return true

    return loadFresh(router.resolve(to).href) ? false : true
  })

  router.afterEach(() => {
    if (Date.now() - lastCheckAt >= CHECK_EVERY_MS) checkForUpdate()
  })

  router.onError((error, to) => {
    if (!isMissingScript(error)) return

    updateWaiting = true
    loadFresh(to?.fullPath ? router.resolve(to).href : window.location.href)
  })

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return
    if (Date.now() - lastCheckAt >= CHECK_ON_RETURN_AFTER_MS) checkForUpdate()
  })

  window.setInterval(() => {
    if (document.visibilityState === 'visible') checkForUpdate()
  }, CHECK_EVERY_MS)
}
