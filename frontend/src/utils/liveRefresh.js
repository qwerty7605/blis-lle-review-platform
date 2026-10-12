// useLiveRefresh: keeps what a page shows current, so nobody has to reload it
// to see what changed.
//
// A page loads its data once, when it opens. Left open, it went on showing
// that: a student did not see the exam their teacher had just assigned, and a
// teacher did not see the students who had just joined the room, until one of
// them reloaded. This runs the page's own quiet refresh
//
//   - when the reader comes back to it (tab shown again, window focused,
//     connection back), if it has been a little while, and
//   - every `every` milliseconds while it is on screen, for the pages people
//     sit and wait on. Pages nobody waits on pass no `every`.
//
// `refresh` has to be quiet: no loading state, nothing selected lost, no
// message cleared, and a failure kept to itself. It is told whether the
// reader has just come back (`returning`), so a page can ask for more then
// than it does on every tick. `when` says whether this is a moment to do it
// at all (not under an open dialog, not during an exam).
import { onBeforeUnmount, onMounted } from 'vue'

// Coming back sooner than this after the last refresh is not worth a request.
const STALE_AFTER_MS = 20 * 1000

export function useLiveRefresh(refresh, { every = 0, when = () => true } = {}) {
  let timer = null
  let lastRun = Date.now()
  let running = false
  let stopped = false

  async function run({ returning = false } = {}) {
    if (running || stopped) return
    if (document.visibilityState !== 'visible' || !when()) return

    running = true

    try {
      await refresh({ returning })
    } catch {
      // Quiet by design: the page keeps what it has and tries again later.
    } finally {
      running = false
      lastRun = Date.now()
    }
  }

  function scheduleNext() {
    window.clearTimeout(timer)
    if (!every || stopped) return

    // Up to a quarter longer, at random. A lab of students opens the same
    // page in the same minute; without this they would all ask again in the
    // same second, every time.
    const wait = every + Math.random() * every * 0.25

    timer = window.setTimeout(async () => {
      await run()
      scheduleNext()
    }, wait)
  }

  function onReturn() {
    if (document.visibilityState !== 'visible') return
    if (Date.now() - lastRun < STALE_AFTER_MS) return

    run({ returning: true })
  }

  onMounted(() => {
    lastRun = Date.now()
    scheduleNext()

    document.addEventListener('visibilitychange', onReturn)
    window.addEventListener('focus', onReturn)
    window.addEventListener('online', onReturn)
    window.addEventListener('pageshow', onReturn)
  })

  onBeforeUnmount(() => {
    stopped = true
    window.clearTimeout(timer)

    document.removeEventListener('visibilitychange', onReturn)
    window.removeEventListener('focus', onReturn)
    window.removeEventListener('online', onReturn)
    window.removeEventListener('pageshow', onReturn)
  })

  return { refreshNow: () => run({ returning: true }) }
}
