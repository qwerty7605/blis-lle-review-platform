// Stops the page scrolling behind an open drawer, sheet, dialog or exam.
// Counted, so a sheet opened while another overlay is up does not unlock the
// page when only one of them closes.
//
// While locked the page also stops answering a pull at its top edge. On a
// phone that pull reloads the page, which is how a swipe down inside a dialog
// could throw away a half-filled form, or a student's place in an exam.
let lockCount = 0
let previousOverflow = ''
let previousOverscroll = ''
let previousBodyOverscroll = ''

export function lockScroll() {
  if (typeof document === 'undefined') return

  if (lockCount === 0) {
    const root = document.documentElement

    previousOverflow = root.style.overflow
    previousOverscroll = root.style.overscrollBehavior
    previousBodyOverscroll = document.body.style.overscrollBehavior

    root.style.overflow = 'hidden'
    // Browsers have read this from <html> or from <body> at different times.
    root.style.overscrollBehavior = 'contain'
    document.body.style.overscrollBehavior = 'contain'
  }

  lockCount += 1
}

export function unlockScroll() {
  if (typeof document === 'undefined' || lockCount === 0) return

  lockCount -= 1

  if (lockCount === 0) {
    document.documentElement.style.overflow = previousOverflow
    document.documentElement.style.overscrollBehavior = previousOverscroll
    document.body.style.overscrollBehavior = previousBodyOverscroll
  }
}
