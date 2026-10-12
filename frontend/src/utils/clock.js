// The time, for anything a page shows that depends on it: whether an exam's
// window has opened, whether it has closed.
//
// Date.now() answers once. A page that read it while drawing "opens at 8:00"
// went on saying so at 8:01, because nothing told it to look again, and the
// student waiting for Take Exam had to reload the page to get it. now() is the
// same number, but reading it in a template or a computed ties that to this
// clock: when the clock beats, whatever read it is worked out again.
//
// It beats every 30 seconds, when the reader comes back to the tab, and at any
// exact moment a caller names with beatAt() (a window's start or end), so a
// button changes when its moment arrives and not up to 30 seconds late.
//
// The time is the server's, not the device's. The server is what decides
// whether an exam can be started, and a phone whose clock is three minutes
// slow would otherwise keep the button disabled for three minutes after the
// exam opened. The difference is learned from the Date header on API
// responses (see api/http.js).
import { ref } from 'vue'

const SLOW_BEAT_MS = 30 * 1000
// setTimeout cannot wait longer than this (about 24.8 days).
const LONGEST_TIMER_MS = 2 ** 31 - 1
// A Date header is whole seconds and the answer took a moment to arrive, so
// two readings a second or two apart are the same clock.
const OFFSET_NOISE_MS = 2000

const beat = ref(0)

let serverOffsetMs = 0
let started = false
let exactTimer = null
let exactAt = Infinity

function start() {
  if (started || typeof window === 'undefined') return
  started = true

  window.setInterval(() => {
    beat.value += 1
  }, SLOW_BEAT_MS)

  // A tab in the background has its timers slowed or stopped, and a phone
  // that was asleep missed them altogether.
  const wake = () => {
    if (document.visibilityState === 'visible') beat.value += 1
  }

  document.addEventListener('visibilitychange', wake)
  window.addEventListener('focus', wake)
  window.addEventListener('pageshow', wake)
}

/** The time now in milliseconds, by the server's clock. Reading it is reactive. */
export function now() {
  start()
  // Read for the dependency, not the value.
  void beat.value

  return Date.now() + serverOffsetMs
}

/**
 * Ask for a beat at an exact time (milliseconds, server clock). Only the
 * nearest one is kept: when it arrives, whatever is on screen is worked out
 * again and asks for the next.
 */
export function beatAt(timestamp) {
  start()
  if (typeof window === 'undefined' || !Number.isFinite(timestamp)) return

  const wait = timestamp - (Date.now() + serverOffsetMs)
  if (wait <= 0 || wait > LONGEST_TIMER_MS || timestamp >= exactAt) return

  exactAt = timestamp
  window.clearTimeout(exactTimer)
  // A little past the moment, so "has it passed?" is true when asked.
  exactTimer = window.setTimeout(() => {
    exactAt = Infinity
    beat.value += 1
  }, wait + 250)
}

/** Called with the Date header of each API response. */
export function noteServerTime(dateHeader) {
  const serverNow = Date.parse(String(dateHeader ?? ''))
  if (!Number.isFinite(serverNow)) return

  // The header is cut to the second: on average the true time is half a
  // second later.
  const offset = serverNow + 500 - Date.now()
  if (Math.abs(offset - serverOffsetMs) < OFFSET_NOISE_MS) return

  serverOffsetMs = offset
  // What was drawn with the old offset may now be on the other side of a
  // start or an end.
  exactAt = Infinity
  window.clearTimeout(exactTimer)
  beat.value += 1
}
