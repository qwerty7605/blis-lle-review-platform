// Signs the reader out after their sign-in has sat unused for too long.
//
// Only for a sign-in made without "Remember me": the server sends its limit
// with the user (`session_idle_minutes`, null when there is none) and refuses
// the token itself once nothing has used it for that long. That covers a tab
// that was closed. It cannot cover one left open, because the pages refresh
// themselves in the background and each refresh is a use of the token; so the
// open page watches for the reader instead: a press, a key, a scroll.
//
// The time of the last one is kept in localStorage, so several tabs count as
// one reader and a tab that is being used keeps the others signed in.
const STORAGE_KEY = 'blis:last-activity'
const CHECK_EVERY_MS = 30 * 1000
// One write for a burst of scrolling or typing is enough.
const NOTE_AT_MOST_EVERY_MS = 5 * 1000

let lastNoted = 0

function readLastActivity() {
  try {
    return Number(window.localStorage.getItem(STORAGE_KEY)) || 0
  } catch {
    return 0
  }
}

export function noteActivity({ force = false } = {}) {
  const at = Date.now()
  if (!force && at - lastNoted < NOTE_AT_MOST_EVERY_MS) return

  lastNoted = at

  try {
    window.localStorage.setItem(STORAGE_KEY, String(at))
  } catch {
    // Private windows may refuse storage; this tab's own record still works.
  }
}

/**
 * @param {() => number|null} idleMinutes  the signed-in reader's limit, or null
 * @param {() => void} onIdle              called once the limit has passed
 */
export function watchForIdle(idleMinutes, onIdle) {
  for (const type of ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll']) {
    window.addEventListener(type, () => noteActivity(), { capture: true, passive: true })
  }

  function check() {
    const minutes = idleMinutes()
    if (!minutes) return

    const last = Math.max(readLastActivity(), lastNoted)

    // No record at all (storage cleared): start counting from now.
    if (!last) {
      noteActivity({ force: true })
      return
    }

    if (Date.now() - last >= minutes * 60 * 1000) onIdle()
  }

  window.setInterval(check, CHECK_EVERY_MS)
  // A laptop lid opened in the morning: check at once, not half a minute in.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') check()
  })
}
