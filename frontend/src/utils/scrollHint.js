// v-scroll-hint: for a row that scrolls sideways with its scrollbar hidden
// (a row of tabs or filter chips on a phone).
//
// Such a row gives no sign that there is more of it: what does not fit is
// simply cut off at the edge. This marks the row with `has-more-start` and
// `has-more-end` while something lies beyond that edge, and the stylesheet
// fades the row out there (see "Rows that scroll sideways" in dashboard.css).
// A row that fits, or one scrolled to its end, is left as it is.
const TOLERANCE = 2

function update(el) {
  const furthest = el.scrollWidth - el.clientWidth

  el.classList.toggle('has-more-start', el.scrollLeft > TOLERANCE)
  el.classList.toggle('has-more-end', el.scrollLeft < furthest - TOLERANCE)
}

export const vScrollHint = {
  mounted(el) {
    const refresh = () => update(el)
    const state = { refresh, resizeObserver: null, mutationObserver: null }

    el.addEventListener('scroll', refresh, { passive: true })

    if (typeof ResizeObserver !== 'undefined') {
      state.resizeObserver = new ResizeObserver(refresh)
      state.resizeObserver.observe(el)
    }

    // Chips come and go with the data (one per room, one per subject).
    if (typeof MutationObserver !== 'undefined') {
      state.mutationObserver = new MutationObserver(refresh)
      state.mutationObserver.observe(el, { childList: true, subtree: true, characterData: true })
    }

    el.__scrollHint = state
    refresh()
  },

  updated(el) {
    el.__scrollHint?.refresh()
  },

  unmounted(el) {
    const state = el.__scrollHint
    if (!state) return

    el.removeEventListener('scroll', state.refresh)
    state.resizeObserver?.disconnect()
    state.mutationObserver?.disconnect()
    delete el.__scrollHint
  },
}
