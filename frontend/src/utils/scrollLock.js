// Stops the page scrolling behind an open drawer or sheet. Counted, so a
// sheet opened while another overlay is up does not unlock the page when
// only one of them closes.
let lockCount = 0
let previousOverflow = ''

export function lockScroll() {
  if (typeof document === 'undefined') return

  if (lockCount === 0) {
    previousOverflow = document.documentElement.style.overflow
    document.documentElement.style.overflow = 'hidden'
  }

  lockCount += 1
}

export function unlockScroll() {
  if (typeof document === 'undefined' || lockCount === 0) return

  lockCount -= 1

  if (lockCount === 0) {
    document.documentElement.style.overflow = previousOverflow
  }
}
